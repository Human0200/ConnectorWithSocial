<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once('./functions.php');
require_once('./service_api.php');
require_once('./settings.php');
require_once('./crest.php');

// Логирование входящих запросов
$input = file_get_contents('php://input');
file_put_contents(__DIR__ . '/handler.txt', date('Y-m-d H:i:s') . " - " . $input . "\n\n", FILE_APPEND);

$data = json_decode($input, true);

// Подключение к БД
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// Функция для вызова методов Битрикс24 с обработкой expired_token
function callBitrixWithTokenRefresh($method, $params, $domain)
{
    // Первый вызов
    $result = CRest::call($method, $params);

    // Проверяем разные варианты ошибки expired_token
    $is_expired_token = false;

    if (isset($result['error']) && $result['error'] === 'expired_token') {
        $is_expired_token = true;
    }

    if (isset($result['error_description']) && strpos($result['error_description'], 'expired_token') !== false) {
        $is_expired_token = true;
    }

    if (isset($result['error_description']) && strpos($result['error_description'], 'The access token provided has expired') !== false) {
        $is_expired_token = true;
    }

    // Если токен истек - обновляем и повторяем
    if ($is_expired_token) {
        file_put_contents(
            __DIR__ . '/token_refresh_log.txt',
            date('Y-m-d H:i:s') . " - Token expired for domain: $domain, refreshing...\n",
            FILE_APPEND
        );

        try {
            // Обновляем токен
            $new_token = refreshBitrixToken($domain);
            file_put_contents(
                __DIR__ . '/token_refresh_log.txt',
                date('Y-m-d H:i:s') . " - Token refreshed successfully for domain: $domain, new token: " . substr($new_token, 0, 10) . "...\n",
                FILE_APPEND
            );

            // ВТОРОЙ ВЫЗОВ: Принудительно передаем новый токен в параметрах
            $params_with_new_token = $params;
            if (!isset($params_with_new_token['auth'])) {
                $params_with_new_token['auth'] = [];
            }
            $params_with_new_token['auth']['access_token'] = $new_token;
            $params_with_new_token['auth']['domain'] = $domain;

            file_put_contents(
                __DIR__ . '/token_refresh_log.txt',
                date('Y-m-d H:i:s') . " - Making second call with new token for domain: $domain\n",
                FILE_APPEND
            );

            $result = CRest::call($method, $params_with_new_token);

            file_put_contents(
                __DIR__ . '/token_refresh_log.txt',
                date('Y-m-d H:i:s') . " - Second call result for $domain: " . (!empty($result['result']) ? 'SUCCESS' : 'FAILED') . "\n",
                FILE_APPEND
            );

            if (!empty($result['error'])) {
                file_put_contents(
                    __DIR__ . '/token_refresh_log.txt',
                    date('Y-m-d H:i:s') . " - Second call error: " . $result['error'] . " - " . ($result['error_description'] ?? '') . "\n",
                    FILE_APPEND
                );
            }
        } catch (Exception $e) {
            file_put_contents(
                __DIR__ . '/token_refresh_log.txt',
                date('Y-m-d H:i:s') . " - Token refresh failed for domain: $domain - " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }

    return $result;
}

// Определение connector_id
$connector_id = null;

// 1. Пробуем получить из домена в запросе
if (!$connector_id && !empty($_REQUEST['DOMAIN'])) {
    $connector_id = getConnectorID($_REQUEST['DOMAIN']);
}

// 2. Пробуем из auth данных
if (!$connector_id && !empty($data['auth']['domain'])) {
    $connector_id = getConnectorID($data['auth']['domain']);
}

// 3. Если это вебхук Telegram - определяем домен по chat_id
if (!$connector_id && !empty($input)) {
    $update = json_decode($input, true);
    if (!empty($update['message']['chat']['id'])) {
        $chat_id = $update['message']['chat']['id'];
        $domain = getDomainByTelegramChat($chat_id);
        if ($domain) {
            $connector_id = getConnectorID($domain);
        }
    }
}

// 4. Пробуем получить connector_id из данных события Битрикс24
if (!$connector_id && !empty($_REQUEST['data']['CONNECTOR'])) {
    $connector_id = $_REQUEST['data']['CONNECTOR'];
}

// 5. Если всё еще не нашли - создаем временный для настройки
if (empty($connector_id)) {
    $connector_id = 'temp_' . bin2hex(random_bytes(8));
}

// --- 1. Активация коннектора в Битрикс24 ---
if (!empty($_REQUEST['PLACEMENT_OPTIONS']) && $_REQUEST['PLACEMENT'] == 'SETTING_CONNECTOR') {
    $options = json_decode($_REQUEST['PLACEMENT_OPTIONS'], true);
    $domain = $_REQUEST['DOMAIN'] ?? $data['auth']['domain'] ?? '';

    $result = callBitrixWithTokenRefresh(
        'imconnector.activate',
        [
            'CONNECTOR' => $connector_id,
            'LINE' => intVal($options['LINE']),
            'ACTIVE' => intVal($options['ACTIVE_STATUS']),
        ],
        $domain
    );

    if (!empty($result['result'])) {
        setLine($options['LINE']);

        // Сохраняем ID открытой линии в БД
        $stmt = $pdo->prepare("UPDATE bitrix_integration_tokens SET id_openline = ? WHERE connector_id = ?");
        $stmt->execute([$options['LINE'], $connector_id]);

        echo '
<style>
    .success-card {
        max-width: 500px;
        margin: 20px auto;
        padding: 20px;
        border-radius: 12px;
        background: #f8f9ff;
        box-shadow: 0 4px 12px rgba(9, 82, 201, 0.15);
        border-left: 6px solid #0952C9;
        font-family: "Segoe UI", Arial, sans-serif;
        color: #333;
    }
    .success-card h3 {
        margin: 0 0 15px 0;
        color: #0952C9;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .success-card .info {
        margin: 5px 0;
        line-height: 1.6;
    }
    .success-card .info strong {
        color: #000;
        width: 180px;
        display: inline-block;
    }
    .icon {
        color: #0952C9;
    }
</style>

<div class="success-card">
    <h3><span class="icon">✅</span> Успешно!</h3>
    <div class="info"><strong>ID LINE:</strong> ' . htmlspecialchars($options['LINE']) . '</div>
    <div class="info"><strong>CONNECTOR:</strong> ' . htmlspecialchars($connector_id) . '</div>
    <div style="margin-top: 15px; font-size: 0.9em; color: #555;">
        <span class="icon">💡</span> Подключение активно и готово к использованию.
    </div>
</div>
';
    } else {
        echo 'Ошибка: ';
        echo print_r($result, true);
    }
}

// --- 2. Прием сообщений ИЗ Битрикс24 (от оператора) в Telegram ---
else if (
    !empty($_REQUEST['event']) && 
    $_REQUEST['event'] == 'ONIMCONNECTORMESSAGEADD' &&
    !empty($_REQUEST['data']['CONNECTOR']) &&
    !empty($_REQUEST['data']['MESSAGES'])
) {
    // Используем connector_id из данных события
    $event_connector_id = $_REQUEST['data']['CONNECTOR'];
    $domain = $_REQUEST['auth']['domain'] ?? $data['auth']['domain'] ?? '';
    
    $log_message = "=== BITRIX TO TELEGRAM ===\n";
    $log_message .= "Data: " . print_r($_REQUEST, true) . "\n";
    $log_message .= "Connector: " . $event_connector_id . "\n";
    $log_message .= "Domain: " . $domain . "\n";
    $log_message .= "Time: " . date('Y-m-d H:i:s') . "\n";
    
    foreach ($_REQUEST['data']['MESSAGES'] as $message) {
        // Извлекаем chat_id и преобразуем его из формата "max_-1003304621681" в "-1003304621681"
        $bitrix_chat_id = $message['chat']['id'];
        $chat_id = str_replace('max_', '', $bitrix_chat_id);
        
        $text = $message['message']['text'] ?? '';
        $text = convertBB($text);
        
        $log_message .= "Chat ID: " . $bitrix_chat_id . " -> " . $chat_id . "\n";
        $log_message .= "Text: " . ($text ?: 'EMPTY') . "\n";

        // Проверяем есть ли файлы
        $files = $message['message']['files'] ?? [];
        $has_files = !empty($files);
        
        $log_message .= "Files count: " . count($files) . "\n";

        $send_result = ['ok' => false];
        
        // Если есть файлы, отправляем их
        if ($has_files) {
            foreach ($files as $file) {
                $file_type = $file['type'] ?? '';
                $file_url = $file['downloadLink'] ?? $file['link'] ?? '';
                $file_name = $file['name'] ?? 'file';
                
                $log_message .= "File: " . $file_name . " (" . $file_type . ") - " . $file_url . "\n";
                
                if ($file_type === 'image' && !empty($file_url)) {
                    // Отправляем фото
                    $send_result = sendServicePhoto($chat_id, $file_url, $text);
                    $log_message .= "Photo send result: " . ($send_result['ok'] ? 'SUCCESS' : 'FAILED') . "\n";
                    
                    // После отправки фото, очищаем текст чтобы не дублировать
                    $text = '';
                } else if (!empty($file_url)) {
                    // Отправляем документ
                    $send_result = sendServiceDocument($chat_id, $file_url, $file_name, $text);
                    $log_message .= "Document send result: " . ($send_result['ok'] ? 'SUCCESS' : 'FAILED') . "\n";
                    
                    // После отправки документа, очищаем текст чтобы не дублировать
                    $text = '';
                }
            }
        }
        
        // Если есть текст (и он еще не был отправлен с файлом), отправляем текстовое сообщение
        if ($text && !$has_files) {
            $send_result = sendServiceMessage($chat_id, $text);
            $log_message .= "Text send result: " . ($send_result['ok'] ? 'SUCCESS' : 'FAILED') . "\n";
        } else if ($text && $has_files) {
            // Если есть и текст и файлы, но текст не был отправлен с файлами - отправляем отдельно
            $send_result = sendServiceMessage($chat_id, $text);
            $log_message .= "Additional text send result: " . ($send_result['ok'] ? 'SUCCESS' : 'FAILED') . "\n";
        }

        // Подтверждаем доставку в Битрикс24 с обработкой expired_token
        if (!empty($send_result['ok'])) {
            $delivery_result = callBitrixWithTokenRefresh(
                'imconnector.send.status.delivery',
                [
                    'CONNECTOR' => $event_connector_id,
                    'LINE' => getLineFromConnectorID($event_connector_id),
                    'MESSAGES' => [
                        [
                            'im' => $message['im'], // Пересылаем элемент 'im' из входящего сообщения
                            'message' => [
                                'id' => is_array($message['message']['id']) ? 
                                       $message['message']['id'] : 
                                       [$message['message']['id']] // Обязательно массив ID
                            ],
                            'chat' => [
                                'id' => $bitrix_chat_id // ID чата во внешней системе
                            ]
                        ]
                    ]
                ],
                $domain
            );
            
            $log_message .= "Delivery confirmed: " . (!empty($delivery_result['result']) ? 'SUCCESS' : 'FAILED') . "\n";
            $log_message .= "Delivery response: " . print_r($delivery_result, true) . "\n";
        } else {
            $error_result = callBitrixWithTokenRefresh(
                'imconnector.send.status.error',
                [
                    'CONNECTOR' => $event_connector_id,
                    'LINE' => getLineFromConnectorID($event_connector_id),
                    'MESSAGES' => [
                        [
                            'im' => $message['im'],
                            'message' => [
                                'id' => is_array($message['message']['id']) ? 
                                       $message['message']['id'] : 
                                       [$message['message']['id']]
                            ],
                            'chat' => [
                                'id' => $bitrix_chat_id
                            ]
                        ]
                    ]
                ],
                $domain
            );
            $log_message .= "Delivery error: " . (!empty($error_result['result']) ? 'SUCCESS' : 'FAILED') . "\n";
        }
        $log_message .= "---\n";
    }
    
    file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'action' => 'bitrix_to_telegram']);
    exit;
}

// --- 3. Вебхук для приема сообщений ИЗ Telegram в Битрикс24 ---
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($input)) {
    $update = json_decode($input, true);

    $log_message = "=== TELEGRAM TO BITRIX ===\n";
    $log_message .= "Time: " . date('Y-m-d H:i:s') . "\n";


    // Обработка текстовых сообщений (только чистый текст без reply на медиа)
    if (
        !empty($update['message']['text']) &&
        empty($update['message']['photo']) &&
        empty($update['message']['document']) &&
        empty($update['message']['voice']) &&
        empty($update['message']['video']) &&
        (empty($update['message']['reply_to_message']) ||
            (empty($update['message']['reply_to_message']['photo']) &&
                empty($update['message']['reply_to_message']['document']) &&
                empty($update['message']['reply_to_message']['voice']) &&
                empty($update['message']['reply_to_message']['video']))
        )
    ) {
        $chat_id = $update['message']['chat']['id'];
        $user_name = $update['message']['from']['first_name'] ?? 'User';
        $text = $update['message']['text'];

        $log_message .= "Chat ID: " . $chat_id . "\n";
        $log_message .= "User: " . $user_name . "\n";
        $log_message .= "Text: " . $text . "\n";
        $log_message .= "Chat type: " . ($update['message']['chat']['type'] ?? 'private') . "\n";

        // Обработка reply сообщений
        if (!empty($update['message']['reply_to_message'])) {
            $reply_to = $update['message']['reply_to_message'];
            $reply_to_message_id = $reply_to['message_id'];
            $reply_to_text = $reply_to['text'] ?? '';
            $reply_to_user = $reply_to['from']['first_name'] ?? 'Unknown';
            $is_reply_to_bot = !empty($reply_to['from']['is_bot']) && $reply_to['from']['is_bot'];

            $log_message .= "REPLY DETECTED:\n";
            $log_message .= "  Reply to message ID: " . $reply_to_message_id . "\n";
            $log_message .= "  Reply to text: " . substr($reply_to_text, 0, 100) . "\n";
            $log_message .= "  Reply to user: " . $reply_to_user . "\n";
            $log_message .= "  Is reply to bot: " . ($is_reply_to_bot ? 'YES' : 'NO') . "\n";

            // Обрезаем длинный текст
            $original_text = trim($reply_to_text);
            if (strlen($original_text) > 100) {
                $original_text = substr($original_text, 0, 100) . '...';
            }

            // Формируем текст с цитированием
            $quote_prefix = $is_reply_to_bot ? "💬 Ответ боту" : "💬 Ответ " . $reply_to_user;
            $text = $quote_prefix . "\n" .
                "> " . str_replace("\n", "\n> ", $original_text) . "\n" .
                $text;
        }

        // Обработка message_thread_id (для тредов в группах)
        if (!empty($update['message']['message_thread_id'])) {
            $thread_id = $update['message']['message_thread_id'];
            $log_message .= "Thread ID: " . $thread_id . "\n";
        }

        // Получаем домен по chat_id
        $domain = getDomainByTelegramChat($chat_id);

        if (!$domain) {
            // Если домен не привязан - обрабатываем как команду
            $log_message .= "Action: Command processed (no domain)\n";
            processBotCommand($chat_id, $update['message']['from']['id'], $text);

            $log_message .= "---\n";
            file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'action' => 'command']);
            exit;
        }

        // Получаем connector_id для домена
        $connector_id = getConnectorID($domain);

        if (!$connector_id) {
            $log_message .= "Error: Connector not found for domain: " . $domain . "\n";
            sendServiceMessage($chat_id, "❌ <b>Ошибка конфигурации!</b>\n\nДомен $domain не настроен в системе. Пожалуйста, введите домен заново.");

            $log_message .= "---\n";
            file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Connector not found']);
            exit;
        }

        $line_id = getLineFromConnectorID($connector_id);

        if (!$line_id) {
            $log_message .= "Error: Line not configured for domain: " . $domain . "\n";
            sendServiceMessage($chat_id, "⚠️ <b>Открытая линия не настроена!</b>\n\nСначала настройте открытую линию в Битрикс24 для домена: " . $domain);

            $log_message .= "---\n";
            file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);

            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Line not configured']);
            exit;
        }

        // Отправляем сообщение в Битрикс24 с обработкой expired_token
        $result = callBitrixWithTokenRefresh(
            'imconnector.send.messages',
            [
                'CONNECTOR' => $connector_id,
                'LINE' => $line_id,
                'MESSAGES' => [
                    [
                        'user' => [
                            'id' => $chat_id,
                            'name' => $user_name
                        ],
                        'message' => [
                            'text' => $text,
                            'date' => time()
                        ],
                        'chat' => [
                            'id' => 'max_' . $chat_id
                        ]
                    ]
                ]
            ],
            $domain
        );

        $log_message .= "Bitrix response: " . (!empty($result['result']) ? 'SUCCESS' : 'FAILED') . "\n";

        if (!empty($result['error'])) {
            $log_message .= "Bitrix error: " . $result['error'] . "\n";
        }

        if (!empty($result['error_description'])) {
            $log_message .= "Bitrix error description: " . $result['error_description'] . "\n";
        }

        if (empty($result['result'])) {
            sendServiceMessage($chat_id, "❌ <b>Ошибка отправки сообщения в Битрикс24</b>\n\nПожалуйста, проверьте настройки интеграции.\nИспользуйте /status для проверки статуса.");
        }

        $log_message .= "---\n";
        file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'action' => 'message_sent']);
        exit;
    }

    // Обработка медиа-сообщений (включая текстовые с reply на медиа)
    else if (!empty($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $user_name = $update['message']['from']['first_name'] ?? 'User';

        $log_message .= "Chat ID: " . $chat_id . "\n";
        $log_message .= "User: " . $user_name . "\n";
        $log_message .= "Media type: ";

        // Получаем домен по chat_id
        $domain = getDomainByTelegramChat($chat_id);

        if ($domain) {
            $connector_id = getConnectorID($domain);
            $line_id = getLineFromConnectorID($connector_id);

            if ($line_id) {
                $messages_to_send = [];

                // Обработка reply для медиа-сообщений
                if (!empty($update['message']['reply_to_message'])) {
                    $reply_to = $update['message']['reply_to_message'];
                    $reply_to_user = $reply_to['from']['first_name'] ?? 'Unknown';
                    $is_reply_to_bot = !empty($reply_to['from']['is_bot']) && $reply_to['from']['is_bot'];

                    // Получаем текст или описание оригинального сообщения
                    $original_content = "";
                    if (!empty($reply_to['text'])) {
                        $original_content = $reply_to['text'];
                    } else if (!empty($reply_to['caption'])) {
                        $original_content = $reply_to['caption'];
                    } else {
                        // Определяем тип медиа
                        if (!empty($reply_to['photo'])) $original_content = "📷 Фото";
                        else if (!empty($reply_to['document'])) $original_content = "📎 Документ";
                        else if (!empty($reply_to['voice'])) $original_content = "🎤 Голосовое сообщение";
                        else if (!empty($reply_to['video'])) $original_content = "🎥 Видео";
                        else if (!empty($reply_to['sticker'])) $original_content = "🖼️ Стикер";
                        else $original_content = "Медиа-сообщение";
                    }

                    // Обрезаем длинный текст
                    if (strlen($original_content) > 100) {
                        $original_content = substr($original_content, 0, 100) . '...';
                    }

                    $reply_text = $is_reply_to_bot ? "💬 Ответ боту:" : "💬 Ответ " . $reply_to_user . ":";
                    $reply_text .= "\n> " . str_replace("\n", "\n> ", $original_content);

                    // Добавляем reply как отдельное сообщение
                    $messages_to_send[] = [
                        'user' => [
                            'id' => $chat_id,
                            'name' => $user_name
                        ],
                        'message' => [
                            'text' => $reply_text,
                            'date' => time()
                        ],
                        'chat' => [
                            'id' => 'max_' . $chat_id
                        ]
                    ];

                    $log_message .= "REPLY DETECTED, ";
                }

                // Обработка основного контента
                $message_data = [
                    'user' => [
                        'id' => $chat_id,
                        'name' => $user_name
                    ],
                    'message' => [
                        'date' => time()
                    ],
                    'chat' => [
                        'id' => 'max_' . $chat_id
                    ]
                ];

                // Если есть reply на фото, отправляем также оригинальное фото
                if (!empty($update['message']['reply_to_message']['photo'])) {
                    $log_message .= "Reply to photo, ";
                    $reply_photo = end($update['message']['reply_to_message']['photo']);
                    $file_info = getFile($reply_photo['file_id']);
                    if ($file_info) {
                        $file_url = getFileLink($file_info['file_path']);

                        // Добавляем оригинальное фото как отдельное сообщение
                        $messages_to_send[] = [
                            'user' => [
                                'id' => $chat_id,
                                'name' => $user_name
                            ],
                            'message' => [
                                'text' => "📷 Оригинальное фото",
                                'files' => [[
                                    'url' => $file_url,
                                    'name' => 'original_photo.jpg',
                                    'type' => 'image/jpeg'
                                ]],
                                'date' => time()
                            ],
                            'chat' => [
                                'id' => 'max_' . $chat_id
                            ]
                        ];
                    }
                }

                if (!empty($update['message']['photo'])) {
                    $log_message .= "Photo\n";
                    $photo = end($update['message']['photo']);
                    $file_info = getFile($photo['file_id']);
                    if ($file_info) {
                        $file_url = getFileLink($file_info['file_path']);
                        $caption = $update['message']['caption'] ?? '';

                        // Для фото отправляем как файл
                        $message_data['message']['text'] = "📷 Фото" . ($caption ? ": " . $caption : "");
                        $message_data['message']['files'] = [[
                            'url' => $file_url,
                            'name' => 'photo.jpg',
                            'type' => 'image/jpeg'
                        ]];
                    }
                } else if (!empty($update['message']['document'])) {
                    $log_message .= "Document\n";
                    $document = $update['message']['document'];
                    $file_info = getFile($document['file_id']);
                    if ($file_info) {
                        $file_url = getFileLink($file_info['file_path']);
                        $caption = $update['message']['caption'] ?? $document['file_name'];

                        $message_data['message']['text'] = "📎 Документ: " . $document['file_name'];
                        if ($caption && $caption != $document['file_name']) {
                            $message_data['message']['text'] .= "\n" . $caption;
                        }

                        $message_data['message']['files'] = [[
                            'url' => $file_url,
                            'name' => $document['file_name'],
                            'type' => $document['mime_type'] ?? 'application/octet-stream'
                        ]];
                    }
                } else if (!empty($update['message']['voice'])) {
                    $log_message .= "Voice\n";
                    $voice = $update['message']['voice'];
                    $file_info = getFile($voice['file_id']);
                    if ($file_info) {
                        $file_url = getFileLink($file_info['file_path']);

                        $message_data['message']['text'] = "🎤 Голосовое сообщение";
                        $message_data['message']['files'] = [[
                            'url' => $file_url,
                            'name' => 'voice.ogg',
                            'type' => 'audio/ogg'
                        ]];
                    }
                } else if (!empty($update['message']['video'])) {
                    $log_message .= "Video\n";
                    $video = $update['message']['video'];
                    $file_info = getFile($video['file_id']);
                    if ($file_info) {
                        $file_url = getFileLink($file_info['file_path']);
                        $caption = $update['message']['caption'] ?? '';

                        $message_data['message']['text'] = "🎥 Видео" . ($caption ? ": " . $caption : "");
                        $message_data['message']['files'] = [[
                            'url' => $file_url,
                            'name' => 'video.mp4',
                            'type' => 'video/mp4'
                        ]];
                    }
                } else if (!empty($update['message']['sticker'])) {
                    $log_message .= "Sticker\n";
                    $sticker = $update['message']['sticker'];
                    $emoji = $sticker['emoji'] ?? '🖼️';
                    $message_data['message']['text'] = $emoji . " Стикер: " . ($sticker['set_name'] ?? '');
                } else if (!empty($update['message']['text'])) {
                    // Это текстовое сообщение с reply на медиа
                    $log_message .= "Text with media reply\n";
                    $message_data['message']['text'] = $update['message']['text'];
                }

                // Добавляем основное сообщение если есть контент
                if (!empty($message_data['message']['text']) || !empty($message_data['message']['files'])) {
                    $messages_to_send[] = $message_data;
                }

                // Отправляем все сообщения в Битрикс
                if (!empty($messages_to_send)) {
                    $result = callBitrixWithTokenRefresh(
                        'imconnector.send.messages',
                        [
                            'CONNECTOR' => $connector_id,
                            'LINE' => $line_id,
                            'MESSAGES' => $messages_to_send
                        ],
                        $domain
                    );
                    $log_message .= "Media sent to Bitrix\n";

                    if (!empty($result['error'])) {
                        $log_message .= "Bitrix error: " . $result['error'] . "\n";
                    }

                    if (!empty($result['error_description'])) {
                        $log_message .= "Bitrix error description: " . $result['error_description'] . "\n";
                    }
                }
            }
        }

        $log_message .= "---\n";
        file_put_contents(__DIR__ . '/app_log.txt', $log_message, FILE_APPEND);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'action' => 'media_processed']);
        exit;
    }
}

// Если ничего не обработано
header('Content-Type: application/json');
echo json_encode(['status' => 'no_action', 'connector_id' => $connector_id]);
