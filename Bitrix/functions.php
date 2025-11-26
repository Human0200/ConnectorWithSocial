<?php

/**
 * Получает connector_id для домена
 */
function getConnectorID($domain)
{
    global $pdo;

    // Ищем существующую запись, где connector_id не пустой
    $stmt = $pdo->prepare("SELECT connector_id FROM bitrix_integration_tokens WHERE domain = ? AND connector_id IS NOT NULL AND connector_id != ''");
    $stmt->execute([$domain]);
    $connector_id = $stmt->fetchColumn();
    
    // Если нашли запись с заполненным connector_id - обновляем last_updated и возвращаем
    if ($connector_id) {
        $stmt = $pdo->prepare("UPDATE bitrix_integration_tokens SET last_updated = NOW() WHERE domain = ?");
        $stmt->execute([$domain]);
        return $connector_id;
    }
    
    // Проверяем, есть ли запись с этим доменом, но без connector_id
    $stmt = $pdo->prepare("SELECT id FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $existing_record = $stmt->fetchColumn();
    
    // Если запись существует, но connector_id пустой - обновляем ее
    if ($existing_record) {
        $connector_id = 'max_' . bin2hex(random_bytes(8));
        
        $stmt = $pdo->prepare(
            "UPDATE bitrix_integration_tokens 
            SET connector_id = ?, last_updated = NOW() 
            WHERE domain = ?"
        );
        $stmt->execute([$connector_id, $domain]);
        
        file_put_contents(__DIR__ . '/connector_creation_log.txt', 
            date('Y-m-d H:i:s') . " - Updated existing record with connector_id: $connector_id for domain: $domain\n", 
            FILE_APPEND
        );
    } else {
        // Если записи вообще нет - создаем новую
        $connector_id = 'max_' . bin2hex(random_bytes(8));
        
        $stmt = $pdo->prepare(
            "INSERT INTO bitrix_integration_tokens 
            (domain, connector_id, last_updated) 
            VALUES (?, ?, NOW())"
        );
        $stmt->execute([$domain, $connector_id]);
        
        file_put_contents(__DIR__ . '/connector_creation_log.txt', 
            date('Y-m-d H:i:s') . " - Created new record with connector_id: $connector_id for domain: $domain\n", 
            FILE_APPEND
        );
    }
    
    return $connector_id;
}

/**
 * Получает домен по ID чата Telegram
 */
function getDomainByTelegramChat($telegram_chat_id)
{
    global $pdo;
    
    $stmt = $pdo->prepare(
        "SELECT domain FROM telegram_chat_connections 
         WHERE telegram_chat_id = ? AND is_active = TRUE 
         ORDER BY updated_at DESC LIMIT 1"
    );
    $stmt->execute([$telegram_chat_id]);
    return $stmt->fetchColumn();
}

/**
 * Получает ID чата Telegram по домену
 */
function getTelegramChatByDomain($domain)
{
    global $pdo;
    
    $stmt = $pdo->prepare(
        "SELECT telegram_chat_id FROM telegram_chat_connections 
         WHERE domain = ? AND is_active = TRUE 
         ORDER BY updated_at DESC LIMIT 1"
    );
    $stmt->execute([$domain]);
    return $stmt->fetchColumn();
}

/**
 * Получает ID открытой линии по connector_id
 */
function getLineFromConnectorID($connector_id)
{
    global $pdo;
    
    if (!$connector_id) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT id_openline FROM bitrix_integration_tokens WHERE connector_id = ?");
    $stmt->execute([$connector_id]);
    return $stmt->fetchColumn();
}

/**
 * Сохраняет связь чата Telegram с доменом
 */
function saveTelegramConnection($domain, $connector_id, $telegram_chat_id)
{
    global $pdo;
    
    // Сначала проверяем, существует ли домен в основной таблице
    $stmt = $pdo->prepare("SELECT connector_id FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $existing_connector = $stmt->fetchColumn();
    
    if (!$existing_connector) {
        return false;
    }
    
    // Используем connector_id из основной таблицы
    $actual_connector_id = $existing_connector;
    
    $stmt = $pdo->prepare(
        "INSERT INTO telegram_chat_connections 
        (domain, connector_id, telegram_chat_id, created_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        connector_id = VALUES(connector_id),
        is_active = TRUE,
        updated_at = NOW()"
    );
    
    return $stmt->execute([$domain, $actual_connector_id, $telegram_chat_id]);
}

/**
 * Обработка команд бота для настройки интеграции
 */
function processBotCommand($chat_id, $user_id, $text, $connector_id = null)
{
    $text = trim($text);
    
    switch ($text) {
        case '/start':
            //sendWelcomeMessage($chat_id);
            processDomainInput($chat_id, 'crm.lead-space.ru');
            break;
            
        case '/help':
            sendHelpMessage($chat_id);
            break;
            
        case '/status':
            sendStatusMessage($chat_id);
            break;
            
        default:
            // Если сообщение похоже на домен - обрабатываем как домен
            if (isValidDomain($text)) {
                processDomainInput($chat_id, $text);
            } else {
                sendUnknownCommandMessage($chat_id);
            }
            break;
    }
}

/**
 * Обработка ввода домена
 */
function processDomainInput($chat_id, $domain)
{
    global $pdo;
    
    // Проверяем существование домена в Битрикс24
    $stmt = $pdo->prepare("SELECT connector_id FROM bitrix_integration_tokens WHERE domain = ?");
    $stmt->execute([$domain]);
    $connector_id = $stmt->fetchColumn();
    
    if ($connector_id) {
        // Домен найден - сохраняем связь
        saveTelegramConnection($domain, $connector_id, $chat_id);
        
        $message = "✅ <b>Домен успешно привязан!</b>\n\n";
        $message .= "🌐 <b>Домен:</b> $domain\n";
        $message .= "🔗 <b>Connector ID:</b> <code>$connector_id</code>\n\n";
        
        // Проверяем, настроена ли линия
        $line_id = getLineFromConnectorID($connector_id);
        if ($line_id) {
            $message .= "📞 <b>Линия:</b> $line_id\n\n";
            $message .= "🎉 <b>Все готово к работе!</b>\n";
            $message .= "Теперь вы можете отправлять сообщения в этот чат и получать ответы из Битрикс24.";
        } else {
            $message .= "⚠️ <b>Линия не настроена</b>\n\n";
            $message .= "Настройте открытую линию в Битрикс24 для завершения интеграции.\n";
        }
        
        $message .= "\nДля проверки статуса используйте /status";
        
    } else {
        $message = "❌ <b>Домен не найден!</b>\n\n";
        $message .= "Сначала установите приложение в Битрикс24 с доменом:\n";
        $message .= "<code>$domain</code>\n\n";
        $message .= "После установки повторите ввод домена.";
    }
    
    sendServiceMessage($chat_id, $message);
}

/**
 * Отправка приветственного сообщения
 */
function sendWelcomeMessage($chat_id)
{
    $message = "👋 <b>Добро пожаловать в интеграцию с Битрикс24!</b>\n\n";
    $message .= "Для подключения мне нужен домен вашего Битрикс24.\n\n";
    $message .= "📝 <b>Введите домен в формате:</b>\n";
    $message .= "<code>yourcompany.bitrix24.ru</code>\n\n";
    $message .= "<i>Этот домен должен совпадать с доменом, где установлено приложение</i>\n\n";
    $message .= "Для справки используйте /help";
    
    sendServiceMessage($chat_id, $message);
}

/**
 * Отправка справки
 */
function sendHelpMessage($chat_id)
{
    $message = "📖 <b>Справка по использованию бота</b>\n\n";
    $message .= "🔹 <b>Основные команды:</b>\n";
    $message .= "/start - начать настройку\n";
    $message .= "/status - показать статус\n";
    $message .= "/help - эта справка\n\n";
    $message .= "🔹 <b>Как подключить:</b>\n";
    $message .= "1. Установите приложение в ваш Битрикс24\n";
    $message .= "2. Введите домен в этот чат\n";
    $message .= "3. Настройте открытую линию в Битрикс24\n\n";
    $message .= "🔹 <b>Формат домена:</b>\n";
    $message .= "<code>вашакомпания.bitrix24.ru</code>\n";
    $message .= "<code>вашакомпания.bitrix24.com</code>";
    
    sendServiceMessage($chat_id, $message);
}

/**
 * Отправка статуса
 */
function sendStatusMessage($chat_id)
{
    $domain = getDomainByTelegramChat($chat_id);
    
    $message = "📊 <b>Статус интеграции</b>\n\n";
    
    if ($domain) {
        $connector_id = getConnectorID($domain);
        $line_id = getLineFromConnectorID($connector_id);
        
        $message .= "✅ <b>Интеграция активна</b>\n\n";
        $message .= "🌐 <b>Домен:</b> $domain\n";
        $message .= "🔗 <b>Connector ID:</b> <code>$connector_id</code>\n";
        $message .= "📞 <b>Линия:</b> " . ($line_id ? $line_id : "❌ не настроена") . "\n\n";
        
        if (!$line_id) {
            $message .= "⚠️ <i>Настройте открытую линию в Битрикс24</i>\n";
        } else {
            $message .= "🎉 <i>Все готово к работе!</i>\n";
            $message .= "Теперь вы можете:\n";
            $message .= "• Отправлять сообщения в этот чат → получать в Битрикс24\n";
            $message .= "• Отвечать в Битрикс24 → получать в этот чат";
        }
    } else {
        $message .= "❌ <b>Интеграция не настроена</b>\n\n";
        $message .= "Для настройки введите домен вашего Битрикс24.\n";
        $message .= "Пример: <code>mycompany.bitrix24.ru</code>";
    }
    
    sendServiceMessage($chat_id, $message);
}

/**
 * Отправка сообщения о неизвестной команде
 */
function sendUnknownCommandMessage($chat_id)
{
    $message = "❓ <b>Неизвестная команда</b>\n\n";
    $message .= "Я понимаю только домены Битрикс24 и команды:\n";
    $message .= "/start - начать настройку\n";
    $message .= "/status - показать статус\n";
    $message .= "/help - справка\n\n";
    $message .= "Введите домен в формате: <code>yourcompany.bitrix24.ru</code>";
    
    sendServiceMessage($chat_id, $message);
}

/**
 * Валидация домена Битрикс24
 */
function isValidDomain($domain)
{
    return preg_match('/^[a-zA-Z0-9.-]+\.bitrix24\.(ru|com|by|kz)$/', $domain);
}

/**
 * Устанавливает линию
 */
function setLine($line_id)
{
    return true;
}

/**
 * Конвертер BB-кодов в HTML для Telegram
 */
function convertBB($var)
{
    $replacements = [
        '/\[b\](.*?)\[\/b\]/is' => '<b>$1</b>',
        '/\[i\](.*?)\[\/i\]/is' => '<i>$1</i>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[s\](.*?)\[\/s\]/is' => '<s>$1</s>',
        '/\[br\]/is' => "\n",
        '/\[code\](.*?)\[\/code\]/is' => '<code>$1</code>',
        '/\[pre\](.*?)\[\/pre\]/is' => '<pre>$1</pre>',
        '/\[url\](.*?)\[\/url\]/is' => '<a href="$1">$1</a>',
        '/\[url=(.*?)\](.*?)\[\/url\]/is' => '<a href="$1">$2</a>',
        '/\[size=(.*?)\](.*?)\[\/size\]/is' => '$2',
        '/\[color=(.*?)\](.*?)\[\/color\]/is' => '$2',
        '/\[quote\](.*?)\[\/quote\]/is' => '« $1 »',
        '/\[quote=(.*?)\](.*?)\[\/quote\]/is' => '« $2 » — $1',
    ];
    
    $result = preg_replace(array_keys($replacements), array_values($replacements), $var);
    $result = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    return $result;
}

/**
 * Обновляет access_token для домена
 */
/**
 * Обновляет access_token для домена
 */
function refreshBitrixToken($domain) {
    global $pdo;
    
    file_put_contents(__DIR__ . '/debug_refresh_function.txt', 
        date('Y-m-d H:i:s') . " - refreshBitrixToken called for domain: $domain\n", 
        FILE_APPEND
    );
    
    // Получаем данные для обновления токена
    $stmt = $pdo->prepare("
        SELECT refresh_token, client_id, client_secret 
        FROM bitrix_integration_tokens 
        WHERE domain = ? 
        LIMIT 1
    ");
    $stmt->execute([$domain]);
    $tokenData = $stmt->fetch();

    file_put_contents(__DIR__ . '/debug_refresh_function.txt', 
        date('Y-m-d H:i:s') . " - Token data from DB: " . print_r($tokenData, true) . "\n", 
        FILE_APPEND
    );

    if (!$tokenData || empty($tokenData['refresh_token'])) {
        throw new Exception("No refresh token available for domain: $domain");
    }

    // Параметры для обновления токена
    $params = [
        'grant_type' => 'refresh_token',
        'client_id' => $tokenData['client_id'],
        'client_secret' => $tokenData['client_secret'],
        'refresh_token' => $tokenData['refresh_token']
    ];
    
    $url = 'https://oauth.bitrix24.tech/oauth/token/?' . http_build_query($params);
    
    file_put_contents(__DIR__ . '/debug_refresh_function.txt', 
        date('Y-m-d H:i:s') . " - Refresh URL: $url\n", 
        FILE_APPEND
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    file_put_contents(__DIR__ . '/debug_refresh_function.txt', 
        date('Y-m-d H:i:s') . " - Refresh response - HTTP: $httpCode, Response: $response\n", 
        FILE_APPEND
    );
    
    if ($httpCode != 200) {
        throw new Exception("Token refresh failed with HTTP code: $httpCode");
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        throw new Exception("Token refresh error: " . ($result['error_description'] ?? $result['error']));
    }
    
    if (!isset($result['access_token']) || !isset($result['expires_in'])) {
        throw new Exception("Invalid token response");
    }

    // Обновляем данные в БД
    $updateStmt = $pdo->prepare("
        UPDATE bitrix_integration_tokens 
        SET access_token = ?,
            token_expires = ?,
            refresh_token = ?,
            last_updated = NOW()
        WHERE domain = ?
    ");
    
    $newExpires = time() + (int)$result['expires_in'];
    $newRefreshToken = $result['refresh_token'] ?? $tokenData['refresh_token'];
    
    $updateResult = $updateStmt->execute([
        $result['access_token'],
        $newExpires,
        $newRefreshToken,
        $domain
    ]);
    
    file_put_contents(__DIR__ . '/debug_refresh_function.txt', 
        date('Y-m-d H:i:s') . " - DB update result: " . ($updateResult ? 'SUCCESS' : 'FAILED') . "\n", 
        FILE_APPEND
    );
    
    if (!$updateResult) {
        throw new Exception("Failed to update tokens in database");
    }
    
    // Логируем успешное обновление
    file_put_contents(__DIR__ . '/token_refresh_log.txt', 
        date('Y-m-d H:i:s') . " - Token refreshed for domain: $domain, expires: $newExpires\n", 
        FILE_APPEND
    );
    
    return $result['access_token'];
}

?>