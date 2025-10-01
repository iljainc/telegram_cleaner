<?php


namespace App\Services;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use danog\MadelineProto\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use danog\MadelineProto\Settings\Ipc;



class TelegramDanogService
{
    protected $MadelineProto;
    protected $settings;
    protected $usersCache = []; // Массив для кеширования пользователей
    protected $sessionFile = ''; // Session file path
    protected $command = null; // Command instance for debugging

    public function __construct($apiId = null, $apiHash = null, $command = null)
    {
        $this->command = $command;
        $this->debug("TelegramDanogService constructor started");
        
        $apiId = $apiId ?? config('telegram.api_id');
        $apiHash = $apiHash ?? config('telegram.api_hash');
        
        $this->debug("API credentials loaded");

        // Ensure storage/app/telegram directory exists
        $telegramDir = storage_path('app/telegram');
        if (!is_dir($telegramDir)) {
            mkdir($telegramDir, 0777, true);
        }

        $md5Hash = md5($apiId.'-'.$apiHash);
        $checksum = substr($md5Hash, 0, 15);
        //print_r($checksum);
        $this->sessionFile = storage_path("app/telegram/session_{$checksum}.madeline");
        $this->debug("Session file: " . $this->sessionFile);

        $this->debug("Creating Settings object");
        
        $this->settings = (new Settings)
            ->setAppInfo(
                (new AppInfo)
                    ->setApiId($apiId)
                    ->setApiHash($apiHash)
            )
            ->setLogger(
                (new LoggerSettings)->setLevel(0)
            );
            
        $this->debug("Settings object created");

        $this->debug("Constructor completed - initSession will be called when needed");
    }

    protected function debug($message)
    {
        if ($this->command) {
            $this->command->info($message);
        } else {
            echo $message . "\n";
        }
    }

    protected function error($message)
    {
        if ($this->command) {
            $this->command->error($message);
        } else {
            echo "ERROR: " . $message . "\n";
        }
    }

    public function initSession()
    {        
        // Protection against multiple API() calls
        if ($this->MadelineProto instanceof \danog\MadelineProto\API) {
            return;
        }

        $this->debug("Creating new API instance");
        
        try {
            $this->MadelineProto = new API($this->sessionFile, $this->settings, [
                'logger' => 0,
                'ipc' => false,
                'peer' => [
                    'cache_full_dialogs' => true,
                ]
            ]);
            
            $this->debug("API instance created, starting session");
            $this->MadelineProto->start();
            $this->debug("Session start completed");
            
            // Disable logging after start
            $this->MadelineProto->updateSettings(
                (new \danog\MadelineProto\Settings\Logger())
                    ->setLevel(0)
            );
            
            $this->debug("Session successfully started");
        } catch (\Exception $e) {
            $this->error("ОШИБКА в initSession: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Send media file to bot and get file ID
     */
    public function sendMediaToBot($originalMedia, $botUsername)
    {
        $this->initSession();
        
        try {
            // Убираем устаревший file_reference из оригинальных данных
            $media = $originalMedia;
            if (isset($media['photo']['file_reference'])) {
                unset($media['photo']['file_reference']);
            }
            if (isset($media['document']['file_reference'])) {
                unset($media['document']['file_reference']);
            }
            
            $result = $this->MadelineProto->messages->sendMedia([
                'peer' => $botUsername,
                'media' => $media
            ]);
                        
            // Получаем file_id из результата
            if (isset($result['media'])) {
                $media = $result['media'];
                if (isset($media['photo'])) {
                    echo "Got photo ID: " . $media['photo']['id'] . "\n";
                    return ['id' => $media['photo']['id']];
                } elseif (isset($media['document'])) {
                    echo "Got document ID: " . $media['document']['id'] . "\n";
                    return ['id' => $media['document']['id']];
                }
            }
            
            // Если нет прямого media, ищем в updates
            if (isset($result['updates']) && is_array($result['updates'])) {
                foreach ($result['updates'] as $update) {
                    if ($update['_'] === 'updateNewMessage' && isset($update['message']['media'])) {
                        $media = $update['message']['media'];
                        if (isset($media['photo'])) {
                            echo "Got photo ID from updates: " . $media['photo']['id'] . "\n";
                            return ['id' => $media['photo']['id']];
                        } elseif (isset($media['document'])) {
                            echo "Got document ID from updates: " . $media['document']['id'] . "\n";
                            return ['id' => $media['document']['id']];
                        }
                    }
                }
            }
            
            echo "No media ID found in response or updates\n";
            return null;
        } catch (\Exception $e) {
            echo "Error sending media to bot: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function closeSession()
    {
        $this->initSession();

        try {
            $this->MadelineProto->stop(); // Остановка текущей сессии
            echo "Session successfully stopped for session\n";
        } catch (\Exception $e) {
            echo "Error stopping session: " . $e->getMessage() . "\n";
        }

        /*
        // Удаление файла сессии
        if (file_exists($this->sessionFile)) {
            rmdir($this->sessionFile);
            echo "Файл сессии удален: {$this->sessionFile}\n";
        } else {
            echo "Файл сессии не найден: {$this->sessionFile}\n";
        }
        */
    }

    /**
     * Получить информацию о пользователе по username
     */
    public function getUserInfoByUsername($username)
    {
        $this->initSession();

        try {
            // Получаем информацию о пользователе по username
            $userInfo = $this->MadelineProto->getInfo('@' . $username);
            $userData = $userInfo['User'] ?? [];

            // Получаем полную информацию о пользователе для доступа к телефону
            $fullInfo = $this->MadelineProto->getFullInfo('@' . $username);
            $fullUserData = $fullInfo['User'] ?? [];

            return [
                'id' => $userData['id'] ?? null,
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'phone' => $fullUserData['phone'] ?? null,
                'email' => $fullUserData['email'] ?? null,
                'json_data' => array_merge($userData, $fullUserData),
            ];
        } catch (\Exception $e) {
            echo "Error getting user info by username: " . $e->getMessage() . "\n";
            return [
                'id' => null,
                'username' => $username,
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'email' => null,
                'json_data' => null,
            ];
        }
    }

    /**
     * Получить информацию о пользователе и сохранить в кеш
     */
    public function getUserInfo($fromId)
    {
        $this->initSession();

        try {
            // Получаем информацию о пользователе через MadelineProto
            $userInfo = $this->MadelineProto->getInfo($fromId);
            $userData = $userInfo['User'] ?? [];

            // Получаем полную информацию о пользователе для доступа к телефону
            $fullInfo = $this->MadelineProto->getFullInfo($fromId);
            $fullUserData = $fullInfo['User'] ?? [];

            return [
                'id' => $fromId,
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'phone' => $fullUserData['phone'] ?? null,
                'email' => $fullUserData['email'] ?? null,
                'json_data' => array_merge($userData, $fullUserData),
            ];
        } catch (\Exception $e) {
            echo "Error getting user info: " . $e->getMessage() . "\n";
            return [
                'id' => $fromId,
                'username' => null,
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'email' => null,
                'json_data' => null,
            ];
        }
    }


    /**
     * Получить список всех групп, в которых состоит пользователь
     *
     * @return array Массив групп с их ID и названиями
     */
    public function getAllGroups()
    {
        $this->initSession();

        $groups = [];

        try {
            // Получаем все диалоги
            $dialogsResponse = $this->MadelineProto->messages->getDialogs(['limit' => 1000]);

            foreach ($dialogsResponse['chats'] as $chat) {
                // Собираем информацию обо всех чатах
                if (isset($chat['title'])) {
                    /*
                     * ХЗ зачем он нужен
                     *
                     *
                    // Получаем полную информацию о канале или группе
                    try {
                        $chatInfo = $this->MadelineProto->getInfo($chat['id']);
                        $accessHash = $chatInfo['Chat']['access_hash'] ?? null;
                    } catch (\Exception $e) {
                        $this->logError("Ошибка при получении информации о чате ID {$chat['id']}: " . $e->getMessage());
                        $accessHash = null;
                    }
                    */

                    $groups[] = [
                        'id' => $chat['id'],
                        'title' => $chat['title'],
                        'username' => $chat['username'] ?? false,
                        'type' => $chat['_'], // Тип чата: channel, chat и т.д.
                        //'access_hash' => $accessHash,  // Добавляем access_hash
                        'is_megagroup' => $chat['megagroup'] ?? false, // Является ли это мегагруппой
                    ];

                    // Если это мегагруппа, можно попытаться получить подгруппы (темы форума)
                    if (($chat['megagroup'] ?? false)) {
                        try {
                            $topicsResponse = $this->MadelineProto->channels->getForumTopics([
                                'channel' => $chat['id'],
                                'limit' => 100, // Установите необходимый лимит
                            ]);

                            if (isset($topicsResponse['topics'])) {
                                foreach ($topicsResponse['topics'] as $topic) {
                                    if (isset($topic['title'])) {
                                        $groups[] = [
                                            'id' => $topic['id'],
                                            'title' => $chat['title'] . ' | ' . $topic['title'],
                                            'type' => 'forum_topic', // Это подгруппа (тема форума)
                                            'parent_group_id' => $chat['id'], // ID родительского форума
//                                           'access_hash' => $accessHash,  // Используем тот же access_hash
                                        ];
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // $this->logError("Ошибка при получении тем для группы ID {$chat['id']}: " . $e->getMessage());
                        }
                    }
                }
            }

            if (empty($groups)) {
                $this->logMessage("Groups and channels not found.");
            } else {
                $this->logMessage("List of groups and channels successfully received.");
            }
        } catch (\Exception $e) {
            $this->logError("Error getting list of groups and channels: " . $e->getMessage());
        }

        return $groups;
    }

    public function getTopicMessages($channelId, $topicId, $days = 30)
    {
        $this->initSession();

        $peer            = $channelId;
        $limit           = 100;
        $cutoffTimestamp = time() - ($days * 86400);

        $this->debug("СЕГОДНЯ: " . date('Y-m-d H:i:s', time()));
        $this->debug("DAYS: {$days}");
        $this->debug("cutoff: {$cutoffTimestamp} (" . date('Y-m-d H:i:s', $cutoffTimestamp) . ")");

        // 1) Берём самый новый reply в треде (верхняя граница для бинпоиска)
        $latest = $this->safeCall(function () use ($peer, $topicId) {
            return $this->MadelineProto->messages->getReplies([
                'peer'        => $peer,
                'msg_id'      => $topicId,
                'offset_id'   => 0,
                'offset_date' => 0,
                'add_offset'  => 0,
                'limit'       => 1,
                'max_id'      => 0,
                'min_id'      => 0,
                'hash'        => 0,
            ]);
        });
        
        $latestMsg = $latest['messages'][0] ?? null;
        if (!$latestMsg) {
            $this->debug("В треде нет ответов");
            return [];
        }

        $latestId = (int)$latestMsg['id'];
        $latestDate = (int)$latestMsg['date'];
        $this->debug("Самое новое сообщение: ID=" . $latestMsg['id'] . ", дата=" . date('Y-m-d H:i:s', $latestDate));
        
        $maxId = 0;

        // Если даже самое новое сообщение старше cutoff - грузим весь топик
        if ($latestDate <= $cutoffTimestamp) {
            $this->debug("Все сообщения в топике старше cutoff - грузим все!");
            $maxId = $latestId;
        } else {
            $maxId = $this->findBoundaryMessage($peer, $topicId, $latestId, $cutoffTimestamp);
            $this->debug("=== КОНЕЦ БИНАРНОГО ПОИСКА ===");
        };

        $messages = [];
        $offset_id = $maxId;
        $i = 1;

        do {
            $this->debug("Запрос пачки: offset_id={$offset_id}, limit={$limit}");

            $resp = $this->safeCall(function () use ($peer, $topicId, $limit, $offset_id) {
                return $this->MadelineProto->messages->getReplies([
                    'peer'        => $peer,
                    'msg_id'      => $topicId,
                    'offset_id'   => $offset_id,
                    'offset_date' => 0,
                    'add_offset'  => 0,
                    'limit'       => $limit,
                    'min_id'      => 0,
                    'hash'        => 0,
                ]);
            });

            $batch = $resp['messages'] ?? [];
            $this->debug("Получено сообщений: " . count($batch));

            if (!$batch) {
                $this->debug("Пустая пачка – конец");
                    break;
                }

            // Фильтруем сообщения по дате
            foreach ($batch as $m) {
                $id = (int)($m['id'] ?? 0);
                $date = (int)($m['date'] ?? 0);
                $dateStr = $date ? date('Y-m-d H:i:s', $date) : '0';
                $cutoffStr = date('Y-m-d H:i:s', $cutoffTimestamp);

                $offset_id = $id;
                
                $msgType = $m['_'] ?? 'unknown';
                $isServiceMsg = ($msgType === 'messageService');
                $isPinned = !empty($m['pinned']);
                
                if ($id == $topicId) {
                    $this->debug("→ ".$i++." id={$id} - КОРНЕВОЙ ТОПИК (тип: {$msgType}), ПРОПУСКАЕМ");
                } elseif ($isServiceMsg) {
                    $this->debug("→ ".$i++." id={$id} - СИСТЕМНОЕ СООБЩЕНИЕ (тип: {$msgType}), ПРОПУСКАЕМ");
                } elseif ($isPinned) {
                    $this->debug("→ ".$i++." id={$id} - ЗАКРЕПЛЁННОЕ СООБЩЕНИЕ, ПРОПУСКАЕМ");
                } elseif ($date && $date <= $cutoffTimestamp) {
                    $messages[] = $m;
                    $this->debug("→ ".$i++." id={$id}, дата={$dateStr} ≤ cutoff={$cutoffStr} ✓ ДОБАВЛЯЕМ");
                } else {
                    $this->debug("→ ".$i++." id={$id}, дата={$dateStr} > cutoff={$cutoffStr} ✗ ПРОПУСКАЕМ");
                }
            }
            
            $this->debug("Ставим offset_id = {$offset_id}");

            usleep(random_int(250000, 500000)); // пауза

        } while (true);
        
        return $messages;
    }

    private function findBoundaryMessage($peer, $topicId, $latestId, $cutoffTimestamp)
    {
        $lo = max((int)$topicId + 1, 1);
        $hi = $latestId;
        $boundaryId = null;

        $this->debug("=== НАЧАЛО БИНАРНОГО ПОИСКА ===");
        $this->debug("Ищем первое сообщение старше " . date('Y-m-d H:i:s', $cutoffTimestamp));
        $this->debug("Диапазон поиска: lo={$lo}, hi={$hi}");

        while ($lo <= $hi) {
            // Если диапазон маленький - берём все сообщения из него
            if ($hi - $lo <= 70) {
                $this->debug("МАЛЕНЬКИЙ ДИАПАЗОН ({$lo}-{$hi}): загружаем все сообщения");
                $resp = $this->safeCall(function () use ($peer, $topicId, $hi, $lo) {
                    return $this->MadelineProto->messages->getReplies([
                        'peer'        => $peer,
                        'msg_id'      => $topicId,
                        'offset_id'   => $hi,
                        'limit'       => 100,
                        'offset_date' => 0,
                        'max_id'      => 0,
                        'min_id'      => 0,
                        'hash'        => 0,
                    ]);
                });
                
                $this->debug("Загружено " . count($resp['messages'] ?? []) . " сообщений из диапазона");
                
                // Ищем первое СТАРОЕ среди загруженных (сообщения идут от новых к старым)
                $prevMsgId = $hi;
                foreach ($resp['messages'] ?? [] as $msg) {
                    $d = (int)($msg['date'] ?? 0);
                    $msgId = (int)($msg['id'] ?? 0);
                    $dateStr = $d ? date('Y-m-d H:i:s', $d) : '0 (нет даты)';
                    $cutoffStr = date('Y-m-d H:i:s', $cutoffTimestamp);
                    
                    $this->debug("Проверяем ID={$msgId}, дата={$dateStr} vs cutoff={$cutoffStr}");
                    
                    if ($d > 0 && $d <= $cutoffTimestamp) {
                        $this->debug("НАЙДЕНО первое СТАРОЕ сообщение: ID={$msgId}");
                        // Возвращаем предыдущий ID (который новее) как границу
                        $return = $prevMsgId ?: $msgId;

                        $this->debug("Return {$return}");

                        return $return;
                    }
                    $prevMsgId = $msgId;
                }
                
                $this->debug("Все сообщения в диапазоне СТАРЫЕ - возвращаем null");
                return null;
            }

            $mid = ($lo + $hi) >> 1; // середина
            $this->debug("Запрашиваем сообщение ID={$mid} (диапазон: {$lo}-{$hi})");
            
            // Берем одно сообщение <= mid
            $resp = $this->safeCall(function () use ($peer, $topicId, $mid) {
                return $this->MadelineProto->messages->getReplies([
                    'peer'        => $peer,
                    'msg_id'      => $topicId,
                    'offset_id'   => $mid,   // встаём на mid
                    'limit'       => 1,      // одно сообщение
                    'offset_date' => 0,
                    'max_id'      => 0,      // не используем max/min в этом варианте
                    'min_id'      => 0,
                    'hash'        => 0,
                ]);
            });

            // Очищаем media для отладки
            // Оставляем только id и date из сообщений
            $debugMessages = [];
            if (isset($resp['messages'])) {
                foreach ($resp['messages'] as $msg) {
                    $debugMessages[] = [
                        'id' => $msg['id'] ?? null,
                        'date' => $msg['date'] ?? null,
                    ];
                }
            }
            //print_r($debugMessages);

            $msg = ($resp['messages'] ?? [])[0] ?? null;

            if (!$msg) {
                // ничего не нашли → идём вправо
                $lo = $mid + 1;
                $this->debug("→ НЕ НАЙДЕН ID≤{$mid}, сдвигаем вправо lo={$lo}");
                continue;
            }

            $d = (int)($msg['date'] ?? 0);
            $dateStr = $d ? date('Y-m-d H:i:s', $d) : '0 (нет даты)';
            $cutoffStr = date('Y-m-d H:i:s', $cutoffTimestamp);
            $actualId = (int)($msg['id'] ?? 0);
            $this->debug("Найден ID={$actualId}, дата={$dateStr}, сравниваем с cutoff={$cutoffStr}");

            if ($d > 0 && $d <= $cutoffTimestamp) {
                $lo = $mid + 1; // сообщение СТАРОЕ → ищем правее первое НОВОЕ
                $this->debug("→ {$dateStr} ≤ {$cutoffStr} (СТАРШЕ cutoff!) ищем правее: lo={$lo}");
            } else {
                $boundaryId = $actualId; // нашли первое НОВОЕ сообщение
                $hi = $actualId - 1; // ищем левее
                $this->debug("→ {$dateStr} > {$cutoffStr} (НОВЕЕ cutoff) Сохраняем boundaryId={$boundaryId}, ищем левее: hi={$hi}");
            }

            // лёгкий троттлинг на бинпоиске
            usleep(120000); // 120 ms
        }

        $this->debug("=== БИНАРНЫЙ ПОИСК ЗАВЕРШЕН: НАЙДЕН boundaryId={$boundaryId} ===");

        return $boundaryId;
    }

    /**
     * Обёртка, которая автоматически пережидает FLOOD_WAIT_xx.
     */
    private function safeCall(callable $fn)
    {
        retry:
        try {
            return $fn();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (preg_match('/FLOOD_WAIT_(\d+)/', $msg, $m)) {
                $wait = (int)$m[1];
                $this->debug("Поймали FLOOD_WAIT_{$wait}, спим {$wait} сек");
                sleep($wait);
                goto retry;
            }

            // другие сетевые таймауты можно мягко ретраить
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
                $this->debug("Timeout, спим 2 сек и ретраим");
                sleep(2);
                goto retry;
            }

            // если это что-то иное — пробрасываем
            throw $e;
        }
    }

    public function deleteMessage($channelId, $messageId)
    {
        $this->initSession();
        
        try {
            $result = $this->MadelineProto->channels->deleteMessages([
                'channel' => $channelId,
                'id' => [$messageId]
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getAllMessages($chatId, $msgId, $console, $minDate = null)
    {
        $this->initSession();

        $dateInfo = '';
        if ($minDate) {
            $dateInfo = ', minDate=' . date('Y-m-d H:i:s', $minDate);
        }
        echo "getAllMessages:: Init for chatId " . json_encode($chatId) . " from id $msgId$dateInfo\n";

        $messagesToSend = [];
        $limit = 100;
        $min_id = $msgId;
        $offset_id = 0;

        try {
            while (true) {
                $sendData = [
                    'peer' => $chatId,
                    'min_id' => $min_id,         // фильтруем всё, что выше заданного ID
                    'offset_id' => $offset_id,   // смещаемся каждый раз
                    'limit' => $limit,
                ];

                $console->info("Requesting batch with all data: min_id={$min_id}, offset_id={$offset_id}, limit={$limit}");

                $response = $this->MadelineProto->messages->getHistory($sendData);
                $msgs = $response['messages'] ?? [];

                if (!empty($msgs)) {
                    $firstMsg = reset($msgs);
                    $firstId = $firstMsg['id'] ?? 'n/a';
                    $firstDate = isset($firstMsg['date']) ? date('Y-m-d H:i:s', $firstMsg['date']) : 'n/a';
                    $lastMsg = end($msgs);
                    $lastId = $lastMsg['id'] ?? 'n/a';
                    $lastDate = isset($lastMsg['date']) ? date('Y-m-d H:i:s', $lastMsg['date']) : 'n/a';
                    $console->info("Received " . count($msgs) . " messages, first message: ID={$firstId}, date={$firstDate}; last message: ID={$lastId}, date={$lastDate}");
                } else {
                    $console->info("Received 0 messages");
                }

                $console->info('TelegramDanogService:: loaded: ' . count($msgs));

                if (empty($msgs)) {
                    $console->info('TelegramDanogService:: break');
                    break;
                }

                // Очистка лишних данных и фильтрация по дате
                foreach ($msgs as $id => $msg) {
                    // Remove heavy and unnecessary fields
                    if (isset($msgs[$id]['media']['webpage']['cached_page'])) {
                        unset($msgs[$id]['media']['webpage']['cached_page']);
                    }
                    if (isset($msgs[$id]['media']['photo']['sizes'])) {
                        unset($msgs[$id]['media']['photo']['sizes']);
                    }
                    if (isset($msgs[$id]['media']['photo']['file_reference'])) {
                        unset($msgs[$id]['media']['photo']['file_reference']);
                    }
                    if (isset($msgs[$id]['media']['webpage']['photo'])) {
                        unset($msgs[$id]['media']['webpage']['photo']);
                    }
                    if (isset($msgs[$id]['media']['document']['thumbs'])) {
                        unset($msgs[$id]['media']['document']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['document']['attributes'])) {
                        unset($msgs[$id]['media']['document']['attributes']);
                    }
                    if (isset($msgs[$id]['media']['document']['file_reference'])) {
                        unset($msgs[$id]['media']['document']['file_reference']);
                    }
                    if (isset($msgs[$id]['media']['video']['thumbs'])) {
                        unset($msgs[$id]['media']['video']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['audio']['thumbs'])) {
                        unset($msgs[$id]['media']['audio']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['sticker']['thumbs'])) {
                        unset($msgs[$id]['media']['sticker']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['voice']['waveform'])) {
                        unset($msgs[$id]['media']['voice']['waveform']);
                    }
                    if (isset($msgs[$id]['media']['poll'])) {
                        unset($msgs[$id]['media']['poll']);
                    }
                    if (isset($msgs[$id]['reply_markup'])) {
                        unset($msgs[$id]['reply_markup']);
                    }

                }

                $messagesToSend = array_merge($messagesToSend, $msgs);

                // Прерывание по дате: если сообщение старше minDate, прекращаем загрузку
                if ($minDate && isset($lastMsg['date']) && $lastMsg['date'] < $minDate) {
                    $console->info('Break by minDate: last message date ' . date('Y-m-d H:i:s', $lastMsg['date']) . ' < minDate ' . date('Y-m-d H:i:s', $minDate));
                    break;
                }

                // Update offset_id to the minimum ID from the current batch
                $ids = array_column($msgs, 'id');
                $minId = !empty($ids) ? min($ids) : $offset_id;
                if ($minId === $offset_id) {
                    $console->error('TelegramDanogService:: break for $minId = '.$minId.', $offset_id = '.$offset_id);
                    break; // Prevent infinite loop
                }
                $offset_id = $minId;

                if ($msgId + 1 == $offset_id) {
                    $console->error('TelegramDanogService:: break for $msgId = '.$msgId.', $offset_id = '.$offset_id);
                    break;
                }
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            $console->error("TelegramDanogService:: Error getting messages: " . $e->getMessage());
        }

        $console->info('TelegramDanogService:: fully loaded: ' . count($messagesToSend));

        return $messagesToSend;
    }

    /**
     * Получить список сообщений для чата с указанной даты
     * Возвращает массив с текстовыми и медиа-сообщениями.
     */
    public function getMessages($chatId, $fromDateTimestamp, $param, $minId = 0)
    {
        $this->initSession();

        echo "getMessages:: Init for chatId ".json_encode($chatId)." fromDateTimestamp $fromDateTimestamp (".date('d.m.y H:i:s', $fromDateTimestamp).") min id ".$minId."\n";

        $messagesToSend = [];
        $offsetId = 0;
        if (empty($param['last_entry'])) $limit = 100;
        else {
            $limit = 1;
            $minId = 0;
        }

        $i = 1;

        do {
            try {
                $sendData = [
                    'offset_id' => $offsetId,
                    'offset_date' => 0,
                    'add_offset' => 0,
                    'limit' => $limit,
                    'max_id' => 0,
                    'min_id' => $minId,
                    'hash' => 0,
                ];

                if (is_array($chatId)) {
                    $sendData['peer'] = $chatId['chatId'];
                } else {
                    $sendData['peer'] = $chatId;
                }

                $threadIds= [];

                if (is_array($chatId) && isset($chatId['threadId'])) {
                    if (is_array($chatId['threadId'])) $threadIds = $chatId['threadId'];
                    else $threadIds[] = $chatId['threadId'];
                } else $threadIds = false;

                $response = $this->MadelineProto->messages->getHistory($sendData);

                // print_r($response['messages']);
                // exit;

                echo "Received messages: " . count($response['messages']) . "\n";

                foreach ($response['messages'] as $i => $message) {
                    $rowLogData = 'i = '.$i.', offsetId = '.$offsetId.' ';

                    if (empty($param['last_entry'])) {
                        // Проверяем, не старше ли сообщение $fromDateTimestamp (если указано)
                        $daysAgo = strtotime('-4 days');
                        if ($message['date'] <= $daysAgo) {
                            $this->logMessage("Throttling by daysAgo " . date('d.m.Y H:i:s', $daysAgo));
                            break 2; // Reached messages older than the specified date
                        }

                        // Проверяем, не старше ли сообщение $fromDateTimestamp (если указано)
                        if ($fromDateTimestamp > 0 && $message['date'] <= $fromDateTimestamp) {
                            $this->logMessage("Throttling by " . date('d.m.Y H:i:s', $message['date']) . ' < ' . date('d.m.Y H:i:s', $fromDateTimestamp));
                            break 2; // Reached messages older than the specified date
                        }
                    };

                    // Это общалка
                    if (!empty($message['from_id'])) {
                        $userInfo = $this->getUserInfo($message['from_id']);

                        if (empty($param['get_username_unknown'])) {
                            // Пропускаем анкноунов
                            if (empty($userInfo['username'])) {
                                $this->logError($rowLogData."Skipping - user does not have username");
                                continue;
                            };
                        };

                        $channel = false;
                    } else {
                        $channel = true;
                    }

                    // Пропускаем реплаи
                    if (empty($threadIds) AND isset($param['get_reply_to']) AND $param['get_reply_to'] == 0 AND !empty($message['reply_to'])) {
                        $this->logError($rowLogData."Skipping replies");
                        continue;
                    };

                    // Проверка обязательных текстов
                    if (isset($param['mandatory_text']) && is_array($param['mandatory_text'])) {
                        foreach ($param['mandatory_text'] as $mandatoryText) {
                            if (strpos($message['message'], $mandatoryText) === false) {
                                $this->logError($rowLogData."Skipping message: mandatory texts missing");
                                continue 2;
                            }
                        }
                    }

                    // Проверка запрещенных текстов
                    if (isset($param['prohibited_text']) && is_array($param['prohibited_text'])) {
                        foreach ($param['prohibited_text'] as $prohibitedText) {
                            if (strpos($message['message'], $prohibitedText) !== false) {
                                $this->logError($rowLogData."Skipping message: prohibited texts found");
                                continue 2;
                            }
                        }
                    }

                    // Проверка группы в канале
                    if (!empty($threadIds)) {
                        if (empty($message['reply_to'])){
                            $this->logError($rowLogData."reply_to not defined for threadIds - skipping");
                            continue;
                        };

                        if (!empty($message['reply_to']['reply_to_top_id'])){
                            $this->logError($rowLogData."Inside threadIds reply - skipping");
                            continue;
                        };

                        if (!in_array($message['reply_to']['reply_to_msg_id'], $threadIds)){
                            $this->logError($rowLogData." => ".$message['reply_to']['reply_to_msg_id']." not in available ".json_encode($threadIds)." reply - skipping");
                            continue;
                        };
                    }

                    // Проверяем наличие grouped_id
                    $groupedId = $message['grouped_id'] ?? 'NoGroup_'.$i++;

                    $messagesToSend[$groupedId]['id'] = $message['id'] ?? null;
                    $messagesToSend[$groupedId]['from_id'] = $userInfo['id'] ?? null;
                    $messagesToSend[$groupedId]['username'] = $userInfo['username'] ?? null;
                    $messagesToSend[$groupedId]['channel'] = $channel;

                    if (!empty($message['message'])) $messagesToSend[$groupedId]['message'] = $message['message'];

                    if (!empty($message['entities'])) $messagesToSend[$groupedId]['entities'] = $message['entities'];

                    // Если сообщение содержит медиа (фото, видео и т.д.)
                    if (isset($message['media'])) {
                        $mediaType = $message['media']['_'];

                        // Только фото и видео (игнорируем другие типы медиа)
                        if (in_array($mediaType, ['messageMediaPhoto', 'messageMediaDocument'])) {
                            $fileId = $message['media']['document']['id'] ?? $message['media']['photo']['id'] ?? null;
                            $accessHash = $message['media']['document']['access_hash'] ?? $message['media']['photo']['access_hash'] ?? null;

                            if ($fileId && $accessHash) {
                                // Добавляем новый файл к существующему сообщению
                                if (isset($messagesToSend[$groupedId]['files']))
                                    $fileIdName = count($messagesToSend[$groupedId]['files']);
                                else $fileIdName = 0;
                                $fileIdName++;

                                $messagesToSend[$groupedId]['files']['file_id_' . $fileIdName] = [
                                    'id' => $fileId,
                                    'access_hash' => $accessHash,
                                    'media_type' => $mediaType
                                ];;
                            }
                        } else if (in_array($mediaType, ['messageMediaGeo'])) {
                            // Создаем новый элемент для grouped_id
                            $messagesToSend[$groupedId]['long']         = $message['media']['geo']['long'];
                            $messagesToSend[$groupedId]['lat']          = $message['media']['geo']['lat'];
                            $messagesToSend[$groupedId]['access_hash']  = $message['media']['geo']['access_hash'];
                        }
                    };

                    if (empty($messagesToSend[$groupedId]['message']) && empty($messagesToSend[$groupedId]['files']) && empty($messagesToSend[$groupedId]['long'])) {
                        $this->logMessage($rowLogData."Empty message group $groupedId - deleting");
                        unset($messagesToSend[$groupedId]);
                    } else $this->logMessage($rowLogData."Message group $groupedId processed");
                }

                $offsetId += $limit;

                if (!empty($param['last_entry'])) {
                    return $messagesToSend;
                };

                //return $messagesToSend;

            } catch (\danog\MadelineProto\RPCErrorException $e) {
                echo "Error getting messages: " . $e->getMessage() . "\n";
                break;
            }

        } while (1 == 0);

        $messagesToSend = array_reverse($messagesToSend, true);

        // print_r($messagesToSend);
        //exit;

        return $messagesToSend; // Возвращаем сообщения в виде массива
    }

    /**
     * Отправить массив сообщений боту
     */
    public function sendMessageToChat($chatId, $message, $threadId = null, $accessHash = null)
    {
        $this->initSession();

        // Проверяем, если сообщение содержит текст и файлы
        $hasText = !empty($message['message']);
        $hasFiles = isset($message['files']) && !empty($message['files']);

        // Если нет файлов и текста — возвращаем
        if (!$hasFiles && !$hasText && empty($message['long']) && empty($message['lat'])) {
            $this->logMessage("Message does not contain text or files for sending.");
            return;
        };

        if (is_string($chatId) && strpos($chatId, '-') === false) {
            // Если это строка и не содержит "-", то это, скорее всего, имя пользователя или ботнейм
            if (strpos($chatId, '@') !== 0) {
                $peer = '@' . $chatId;
            } else {
                $peer = $chatId;  // Оставляем без изменений
            }
        } else {
            // Если это числовой ID (для групп и каналов, например, начинается с -100)
            $peer = $chatId;
        }

        $sendData = ['peer' => $peer];

        if (!empty($threadId)) {// Преобразуем channel_id в положительное число
            $sendData['reply_to_msg_id'] = $threadId;
        };

        // [
        //                'disable_web_page_preview' => true,
        //            ]

        $sendData['message'] = $message['message'] ?? ''; // Добавляем подпись, если есть
        //if (!empty($sendData['message'])) $sendData['disable_web_page_preview'] = true;
        if (!empty($message['entities'])) $sendData['entities'] = $message['entities'];

        // Если сообщение содержит только текст
        if (!empty($message['long']) && !empty($message['lat']) && !empty($message['access_hash'])) {
            // Определяем тип медиа в зависимости от MIME-типа
            $media = [
                '_' => 'inputMediaGeoPoint',
                'geo_point' => [
                    '_' => 'inputGeoPoint',
                    'lat' => $message['lat'], // Широта
                    'long' => $message['long'], // Долгота
                    'access_hash' => $message['access_hash'] // access_hash из вашего массива
                ],
            ];

            try {
                $sendData['media'] = $media;

                $this->MadelineProto->messages->sendMedia($sendData);
                $this->logMessage("Geo position sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending geo position: " . $e->getMessage());
            }
        } elseif ($hasText && !$hasFiles) {
            // Отправляем текстовое сообщение
            try {
                $this->MadelineProto->messages->sendMessage($sendData);
                $this->logMessage("Text message sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending text message: " . $e->getMessage());
            }
        // Если сообщение содержит 1 файл
        } else if (count($message['files']) === 1) {
            $file = reset($message['files']); // Получаем первый (и единственный) элемент

            // Убедимся, что файл содержит необходимые данные
            if (!isset($file['id']) || !isset($file['access_hash'])) {
                $this->logError("File does not contain 'id' or 'access_hash'. Skipping.");
                return;
            }

            $mediaType = $file['media_type'];
            // ['messageMediaPhoto', 'messageMediaDocument']

            // Определяем тип медиа в зависимости от MIME-типа
            if ($mediaType == 'messageMediaPhoto') {
                // Если это изображение
                $media = [
                    '_' => 'inputMediaPhoto',
                    'id' => [
                        '_' => 'inputPhoto',
                        'id' => $file['id'],
                        'access_hash' => $file['access_hash'],
                    ],
                ];
            } elseif ($mediaType == 'messageMediaDocument') {
                // Если это видео
                $media = [
                    '_' => 'inputMediaDocument', // Для видео используем inputMediaDocument
                    'id' => [
                        '_' => 'inputDocument', // Для видео используем inputDocument
                        'id' => $file['id'],
                        'access_hash' => $file['access_hash'],
                    ],
                ];
            };

            // Отправляем одиночное медиа сообщение
            try {
                $sendData['media'] = $media;

                $this->MadelineProto->messages->sendMedia($sendData);
                $this->logMessage("Media message sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending media message: " . $e->getMessage());
            }
        } else {
            $mediaGroup = [];
            foreach ($message['files'] as $file) {
                // Убедимся, что файл содержит необходимые данные
                if (!isset($file['id']) || !isset($file['access_hash'])) {
                    $this->logMessage("File does not contain 'id' or 'access_hash'. Skipping.");
                    continue;
                }

                $mediaType = $file['media_type'];
                // ['messageMediaPhoto', 'messageMediaDocument']

                // Определяем тип медиа в зависимости от MIME-типа
                if ($mediaType == 'messageMediaPhoto') {
                    // Если это изображение
                    $media = [
                        '_' => 'inputMediaPhoto',
                        'id' => [
                            '_' => 'inputPhoto',
                            'id' => $file['id'],
                            'access_hash' => $file['access_hash'],
                        ],
                    ];
                } elseif ($mediaType == 'messageMediaDocument') {
                    // Если это видео
                    $media = [
                        '_' => 'inputMediaDocument', // Для видео используем inputMediaDocument
                        'id' => [
                            '_' => 'inputDocument', // Для видео используем inputDocument
                            'id' => $file['id'],
                            'access_hash' => $file['access_hash'],
                        ],
                    ];
                };

                // Формируем данные для каждого файла
                $mediaGroup[] = [
                    '_' => 'inputSingleMedia',
                    'media' => $media,
                    'random_id' => mt_rand(), // Уникальный random_id для каждого файла
                    'message' => $message['message'] ?? '', // Используем 'message' для подписи
                ];
            }

            if (empty($mediaGroup)) {
                $this->logMessage("No valid files for sending.");
                return;
            }

            $sendDataMultimedia = $sendData;
            $sendDataMultimedia['multi_media'] = $mediaGroup;

            // Попробуем отправить медиагруппу с использованием 'sendMultiMedia'
            try {
                $this->MadelineProto->messages->sendMultiMedia($sendDataMultimedia);
                $this->logMessage("Media group sent to bot {$peer} with text.");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending media group: " . $e->getMessage());
            };

            // Попробуем отправить медиагруппу с использованием 'sendMultiMedia'
            try {
                $this->MadelineProto->messages->sendMessage($sendData);
                $this->logMessage("Text message from media group sent to bot {$peer} with text.");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending text message from media group: " . $e->getMessage());
            };
        }

        usleep(300000); // 0.3 секунды
        $this->logMessage("0,3 sec");
    }

    /**
     * Логирует сообщение в зависимости от того, запущено ли приложение в консоли.
     */
    protected function logMessage($message)
    {
        if (app()->runningInConsole()) {
            echo $message . "\n";
        } else {
            Log::info($message);
        }
    }

    /**
     * Логирует ошибки в зависимости от того, запущено ли приложение в консоли.
     */
    protected function logError($message)
    {
        if (app()->runningInConsole()) {
            echo "Error: " . $message . "\n";
        } else {
            Log::error($message);
        }
    }

    public function getUserById($fromId): ?array
    {
        $this->initSession();

        try {
            $info = $this->MadelineProto->getFullInfo($fromId);
            return $info['User'] ?? null;
        } catch (\Throwable $e) {
            $this->logError("getUserById: error getting data by ID $fromId: " . $e->getMessage());
            return null;
        }
    }

}
