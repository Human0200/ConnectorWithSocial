<?php
/**
 * Скрипт для первоначальной настройки вебхука Telegram
 * Запустить один раз после установки токена бота
 */

require_once('service_api.php');

// URL вашего обработчика
$webhook_url = 'https://bitrix-connector.lead-space.ru/connector_max/Bitrix/handler.php';

// Токен бота (прямо здесь)
$token = "8488931341:AAE0ofTxsIPhUCXDmMHt7Q5VktmDtx0S_1g";

echo "<h2>Настройка Telegram вебхука</h2>";

// Получаем информацию о боте
echo "<h3>1. Информация о боте:</h3>";
$bot_info = getBotInfo();
if ($bot_info && $bot_info['ok']) {
    echo "<pre>";
    echo "Имя бота: " . $bot_info['result']['first_name'] . "\n";
    echo "Username: @" . $bot_info['result']['username'] . "\n";
    echo "ID: " . $bot_info['result']['id'] . "\n";
    echo "</pre>";
} else {
    echo "<p style='color:red;'>Ошибка получения информации о боте. Проверьте токен!</p>";
    echo "<pre>" . print_r($bot_info, true) . "</pre>";
    exit;
}

// Устанавливаем вебхук
echo "<h3>2. Установка вебхука:</h3>";
echo "<p>URL: <code>{$webhook_url}</code></p>";

// Добавим проверку существования функции
if (!function_exists('setTelegramWebhook')) {
    echo "<p style='color:red;'>Функция setTelegramWebhook не найдена в service_api.php</p>";
    exit;
}

$result = setTelegramWebhook($webhook_url);

echo "<p>Результат установки вебхука:</p>";
echo "<pre>" . print_r($result, true) . "</pre>";

if ($result && $result['ok']) {
    echo "<p style='color:green;'>✓ Вебхук успешно установлен!</p>";
} else {
    echo "<p style='color:red;'>✗ Ошибка установки вебхука:</p>";
    if (isset($result['description'])) {
        echo "<p>Описание ошибки: " . $result['description'] . "</p>";
    }
}

// Проверяем информацию о вебхуке
echo "<h3>3. Проверка вебхука:</h3>";
$check_url = 'https://api.telegram.org/bot' . $token . '/getWebhookInfo';

// Настраиваем контекст для лучшей обработки ошибок
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'ignore_errors' => true
    ]
]);

$response = @file_get_contents($check_url, false, $context);
if ($response === false) {
    echo "<p style='color:red;'>Ошибка при запросе информации о вебхуке. Проверьте доступность Telegram API.</p>";
    $error = error_get_last();
    echo "<p>Ошибка: " . $error['message'] . "</p>";
} else {
    $webhook_info = json_decode($response, true);
    if ($webhook_info && $webhook_info['ok']) {
        echo "<pre>";
        echo "URL: " . ($webhook_info['result']['url'] ?: 'не установлен') . "\n";
        echo "Pending updates: " . ($webhook_info['result']['pending_update_count'] ?? 0) . "\n";
        if (!empty($webhook_info['result']['last_error_message'])) {
            echo "Последняя ошибка: " . $webhook_info['result']['last_error_message'] . "\n";
            echo "Время ошибки: " . date('Y-m-d H:i:s', $webhook_info['result']['last_error_date']) . "\n";
        }
        echo "</pre>";
        
        // Проверяем, совпадает ли URL
        if ($webhook_info['result']['url'] === $webhook_url) {
            echo "<p style='color:green;'>✓ Вебхук установлен корректно</p>";
        } else {
            echo "<p style='color:orange;'>⚠ URL вебхука не совпадает с ожидаемым</p>";
        }
    } else {
        echo "<p style='color:red;'>Не удалось получить информацию о вебхуке</p>";
        echo "<pre>" . print_r($webhook_info, true) . "</pre>";
    }
}

echo "<hr>";
echo "<h3>Что дальше?</h3>";
echo "<ol>";
echo "<li>Убедитесь, что вебхук установлен успешно</li>";
echo "<li>Добавьте бота в Telegram-группу</li>";
echo "<li>Отправьте первое сообщение в группе</li>";
echo "<li>Проверьте, что открытая линия создалась в Bitrix24</li>";
echo "</ol>";

echo "<h3>Полезные ссылки:</h3>";
echo "<ul>";
echo "<li><a href='https://t.me/" . $bot_info['result']['username'] . "' target='_blank'>Открыть бота в Telegram</a></li>";
echo "<li><a href='test_connection.php'>Проверить подключение к Bitrix24</a></li>";
echo "<li><a href='view_chats.php'>Посмотреть активные чаты</a></li>";
echo "</ul>";

// Дополнительная отладочная информация
echo "<hr>";
echo "<h3>Отладочная информация:</h3>";
echo "<p>Токен: " . substr($token, 0, 10) . "..." . "</p>";
echo "<p>Время выполнения: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP версия: " . PHP_VERSION . "</p>";
?>