<?php
require_once('settings.php');

// Получаем токен бота из константы
function getServiceToken()
{
    return defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : null;
}

// Отправка сообщения в Telegram
function sendServiceMessage($chat_id, $text, $parse_mode = 'HTML') {
    $token = getServiceToken();
    
    if (!$token) {
        file_put_contents(__DIR__ . '/errors.txt', 
            date('[Y-m-d H:i:s] ') . "Telegram token not configured\n", 
            FILE_APPEND
        );
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    // Логирование
    file_put_contents(__DIR__ . '/telegram_send.txt', 
        date('[Y-m-d H:i:s] ') . "Chat: $chat_id, HTTP: $http_code, Response: " . $response . "\n", 
        FILE_APPEND
    );
    
    return $result;
}

// Установка вебхука для Telegram
function setTelegramWebhook($webhook_url) {
    $token = getServiceToken();
    
    if (!$token) {
        return ['ok' => false, 'error' => 'Token not found'];
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/setWebhook';
    $data = [
        'url' => $webhook_url,
        'allowed_updates' => ['message']
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Удаление вебхука
function deleteTelegramWebhook() {
    $token = getServiceToken();
    
    if (!$token) {
        return ['ok' => false, 'error' => 'Token not found'];
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/deleteWebhook';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Получение информации о боте
function getBotInfo() {
    $token = getServiceToken();
    
    if (!$token) {
        return null;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/getMe';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        file_put_contents(__DIR__ . '/errors.txt', 
            date('[Y-m-d H:i:s] ') . "getMe failed: HTTP $http_code, Response: $response\n", 
            FILE_APPEND
        );
        return null;
    }
    
    return json_decode($response, true);
}

// Получение информации о чате
function getChatInfo($chat_id) {
    $token = getServiceToken();
    
    if (!$token) {
        return null;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/getChat';
    $data = [
        'chat_id' => $chat_id
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return null;
    }
    
    return json_decode($response, true);
}

// Отправка файла в Telegram
function sendDocument($chat_id, $file_url, $caption = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendDocument';
    $data = [
        'chat_id' => $chat_id,
        'document' => $file_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка фото в Telegram
function sendPhoto($chat_id, $photo_url, $caption = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendPhoto';
    $data = [
        'chat_id' => $chat_id,
        'photo' => $photo_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка аудио в Telegram
function sendAudio($chat_id, $audio_url, $caption = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendAudio';
    $data = [
        'chat_id' => $chat_id,
        'audio' => $audio_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка видео в Telegram
function sendVideo($chat_id, $video_url, $caption = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendVideo';
    $data = [
        'chat_id' => $chat_id,
        'video' => $video_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка голосового сообщения в Telegram
function sendVoice($chat_id, $voice_url, $caption = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendVoice';
    $data = [
        'chat_id' => $chat_id,
        'voice' => $voice_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка локации в Telegram
function sendLocation($chat_id, $latitude, $longitude) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendLocation';
    $data = [
        'chat_id' => $chat_id,
        'latitude' => $latitude,
        'longitude' => $longitude
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка контакта в Telegram
function sendContact($chat_id, $phone_number, $first_name, $last_name = null) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendContact';
    $data = [
        'chat_id' => $chat_id,
        'phone_number' => $phone_number,
        'first_name' => $first_name
    ];
    
    if ($last_name) {
        $data['last_name'] = $last_name;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Редактирование сообщения в Telegram
function editMessageText($chat_id, $message_id, $text, $parse_mode = 'HTML') {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/editMessageText';
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Удаление сообщения в Telegram
function deleteMessage($chat_id, $message_id) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/deleteMessage';
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка действия (печатает, отправляет фото и т.д.)
function sendChatAction($chat_id, $action) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    // Допустимые действия: typing, upload_photo, record_video, upload_video, record_voice, upload_voice, upload_document, find_location, record_video_note, upload_video_note
    $allowed_actions = ['typing', 'upload_photo', 'record_video', 'upload_video', 'record_voice', 'upload_voice', 'upload_document', 'find_location', 'record_video_note', 'upload_video_note'];
    
    if (!in_array($action, $allowed_actions)) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendChatAction';
    $data = [
        'chat_id' => $chat_id,
        'action' => $action
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Получение информации о файле
function getFile($file_id) {
    $token = getServiceToken();
    
    if (!$token) {
        return null;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/getFile';
    $data = [
        'file_id' => $file_id
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (!$result['ok']) {
        return null;
    }
    
    return $result['result'];
}

// Получение ссылки на файл
function getFileLink($file_path) {
    $token = getServiceToken();
    
    if (!$token) {
        return null;
    }
    
    return "https://api.telegram.org/file/bot{$token}/{$file_path}";
}

// Получение обновлений (для polling)
function getUpdates($offset = null, $limit = 100, $timeout = 0) {
    $token = getServiceToken();
    
    if (!$token) {
        return null;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/getUpdates';
    $data = [
        'limit' => $limit,
        'timeout' => $timeout
    ];
    
    if ($offset !== null) {
        $data['offset'] = $offset;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Отправка сообщения с клавиатурой
function sendMessageWithKeyboard($chat_id, $text, $keyboard, $parse_mode = 'HTML') {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'reply_markup' => $keyboard
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Создание клавиатуры
function createReplyKeyboard($keyboard, $resize_keyboard = true, $one_time_keyboard = false) {
    return [
        'keyboard' => $keyboard,
        'resize_keyboard' => $resize_keyboard,
        'one_time_keyboard' => $one_time_keyboard
    ];
}

// Создание inline клавиатуры
function createInlineKeyboard($inline_keyboard) {
    return [
        'inline_keyboard' => $inline_keyboard
    ];
}

// Удаление клавиатуры
function removeKeyboard($chat_id, $text) {
    $token = getServiceToken();
    
    if (!$token) {
        return false;
    }
    
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => ['remove_keyboard' => true]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
// Функция для отправки фото в Telegram
function sendServicePhoto($chat_id, $photo_url, $caption = '') {
    $telegram_token = getServiceToken();
    $api_url = "https://api.telegram.org/bot{$telegram_token}/sendPhoto";
    
    $data = [
        'chat_id' => $chat_id,
        'photo' => $photo_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
        $data['parse_mode'] = 'HTML';
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Функция для отправки документа в Telegram
function sendServiceDocument($chat_id, $document_url, $filename = 'document', $caption = '') {
    $telegram_token = getServiceToken();
    $api_url = "https://api.telegram.org/bot{$telegram_token}/sendDocument";
    
    $data = [
        'chat_id' => $chat_id,
        'document' => $document_url
    ];
    
    if ($caption) {
        $data['caption'] = $caption;
        $data['parse_mode'] = 'HTML';
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>