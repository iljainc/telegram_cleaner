<?php
namespace App\Services;

use App\Models\TelegramLog;
use App\Models\TelegramUser;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class TelegramService
{
    private static $token;

    private static function getToken()
    {
        if (!self::$token) {
            self::$token = env('TELEGRAM_TOKEN');
        }

        return self::$token;
    }

    public static function sendMessage($chatId, $message, $keyboard = '', $type = '', $mediaFiles = [], $entities = null, $topicId = null)
    {
        $results = [];
        
        // Convert entities format if needed (from Telegram format to API format)
        if (!empty($entities) && is_array($entities)) {
            // Mapping of Telegram entity types to API types (only supported types)
            $typeMapping = [
                'messageEntityPhone' => 'phone_number',
                'messageEntityBold' => 'bold',
                'messageEntityItalic' => 'italic',
                'messageEntityCode' => 'code',
                'messageEntityPre' => 'pre',
                'messageEntityTextUrl' => 'text_link',
                'messageEntityMention' => 'mention',
                'messageEntityHashtag' => 'hashtag',
                'messageEntityCashtag' => 'cashtag',
                'messageEntityBotCommand' => 'bot_command',
                'messageEntityUrl' => 'url',
                'messageEntityEmail' => 'email',
            ];
            
            // Step 1: Convert "_" field to "type" field for Telegram API  
            $entities = collect($entities)->map(function($entity) use ($typeMapping) {
                if (isset($entity['_'])) {
                    $entity['type'] = $typeMapping[$entity['_']] ?? strtolower(str_replace('messageEntity', '', $entity['_']));
                    unset($entity['_']);
                }
                return $entity;
            })->toArray();
            
            // Step 2: Filter only supported entity types for Bot API
            $entities = collect($entities)->filter(function($entity) {
                $supportedTypes = [
                    'mention', 'hashtag', 'cashtag', 'bot_command', 'url', 'email', 
                    'phone_number', 'bold', 'italic', 'code', 'pre', 'text_link', 'text_mention'
                ];
                
                return isset($entity['type']) && in_array($entity['type'], $supportedTypes);
            })->toArray();
        }
        
        // Check if we have media files (works with arrays and collections)
        if (!empty($mediaFiles) && count($mediaFiles) > 0) {
            // Don't send keyboard with media files
            $keyboard = '';
            
            // Check if caption is too long (Telegram limit is 1024 characters)
            $captionToSend = '';
            $sendTextSeparately = false;
            
            if (strlen($message) > 1024) {
                $sendTextSeparately = true;
            } else {
                $captionToSend = $message;
            }
            
            if (count($mediaFiles) == 1) {
                // Single media file
                $mediaFile = $mediaFiles[0];
                $mediaField = 'document'; // default
                $command = 'sendDocument';
                
                // Determine media type
                if (isset($mediaFile->file_type)) {
                    if ($mediaFile->file_type === 'photo') {
                        $mediaField = 'photo';
                        $command = 'sendPhoto';
                    } elseif ($mediaFile->file_type === 'video') {
                        $mediaField = 'video';
                        $command = 'sendVideo';
                    } elseif ($mediaFile->file_type === 'audio') {
                        $mediaField = 'audio';
                        $command = 'sendAudio';
                    }
                }
                
                $json = [
                    'json' => [
                        'chat_id' => $chatId,
                        $mediaField => $mediaFile->telegram_file_id
                    ]
                ];
                
                // Add caption only if it's not too long
                if (!empty($captionToSend)) {
                    $json['json']['caption'] = $captionToSend;
                    
                    // Add caption_entities if provided, otherwise use parse_mode
                    if (!empty($entities)) {
                        $json['json']['caption_entities'] = $entities;
                    } else {
                        $json['json']['parse_mode'] = 'HTML';
                    }
                }
                
                // Add topic ID if provided
                if (!empty($topicId)) {
                    $json['json']['message_thread_id'] = $topicId;
                }
                
                $results[] = self::send($chatId, $json, $command, $type);
                
                // Send text separately if caption was too long
                if ($sendTextSeparately && !empty($message)) {
                    $results[] = self::sendMessage($chatId, $message, '', $type, [], $entities, $topicId);
                }
            } else {
                // Media group (multiple files)
                $media = [];
                foreach ($mediaFiles as $index => $mediaFile) {
                    $mediaItem = [
                        'type' => $mediaFile->file_type,
                        'media' => $mediaFile->telegram_file_id
                    ];
                    
                    // Add caption only to first media file if not too long
                    if ($index === 0 && !empty($captionToSend)) {
                        $mediaItem['caption'] = $captionToSend;
                        
                        // Add caption_entities if provided, otherwise use parse_mode
                        if (!empty($entities)) {
                            $mediaItem['caption_entities'] = $entities;
                        } else {
                            $mediaItem['parse_mode'] = 'HTML';
                        }
                    }
                    
                    $media[] = $mediaItem;
                }
                
                $json = [
                    'json' => [
                        'chat_id' => $chatId,
                        'media' => $media
                    ]
                ];
                
                // Add topic ID if provided
                if (!empty($topicId)) {
                    $json['json']['message_thread_id'] = $topicId;
                }
                
                $results[] = self::send($chatId, $json, 'sendMediaGroup', $type);
                
                // Send text separately if caption was too long
                if ($sendTextSeparately && !empty($message)) {
                    $results[] = self::sendMessage($chatId, $message, '', $type, [], $entities, $topicId);
                }
            }
        } else {
            // Text message only
            $json = [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $message
                ]
            ];
            
            // Add entities if provided, otherwise use parse_mode
            if (!empty($entities)) {
                $json['json']['entities'] = $entities;
            } else {
                $json['json']['parse_mode'] = 'HTML';
            }
            
            // Add keyboard if provided
            if (!empty($keyboard)) {
                $json['json']['reply_markup'] = json_encode($keyboard);
            }
            
            // Add topic ID if provided
            if (!empty($topicId)) {
                $json['json']['message_thread_id'] = $topicId;
            }
            
            $results[] = self::send($chatId, $json, 'sendMessage', $type);
        }
        
        // Return single result if only one, otherwise return array
        return count($results) == 1 ? $results[0] : $results;
    }

    public static function sendTypingAction($chatId)
    {
        $json = [
            'json' => [
                'chat_id' => $chatId,
                'action' => 'typing'
            ]
        ];

        return self::send($chatId, $json, 'sendChatAction');
    }

    public static function sendMessageToAdmin($message, $keyboard = '', $type = '', $mediaFiles = [], $entities = null)
    {
        $adminTids = config('app.admin_tids');
        $results = [];

        foreach ($adminTids as $chatId) {
            $results[] = self::sendMessage($chatId, $message, $keyboard, $type, $mediaFiles, $entities);
        }

        return $results;
    }


    public static function send($chatId, $json, $command, $type = '') {
        $client = new Client();

        try {
            $response = $client->request('POST', 'https://api.telegram.org/bot' . self::getToken() . '/'.$command, $json);
            
            // Get response body
            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);
            
            // Extract message_id from response if successful
            $messageId = null;
            if (isset($responseData['result']['message_id'])) {
                // Single message
                $messageId = [$responseData['result']['message_id']];
            } elseif (isset($responseData['result']) && is_array($responseData['result'])) {
                // Media group - array of messages
                $messageId = array_column($responseData['result'], 'message_id');
            }

            if (empty($type)) $type = $command;
            
            // Successful message - unblock user
            self::updateUserActivityStatus($chatId, TelegramUser::ACTIVITY_STATUS_ACTIVE);
            
            return self::log($chatId, $json, null, $messageId, $type, $responseBody);
        } catch (ConnectException $e) {
            $error = 'Connection error: ' . $e->getMessage();
            return self::log($chatId, $json, $error, null, $type);
        } catch (RequestException $e) {
            $responseBody = null;
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $error = $responseBody;
                
                // Check if user blocked the bot
                if (self::isUserBlockedBot($responseBody)) {
                    self::updateUserActivityStatus($chatId, TelegramUser::ACTIVITY_STATUS_BLOCKED);
                }
            } else {
                $error = $e->getMessage();
            }
            return self::log($chatId, $json, $error, null, $type, $responseBody);
        }
    }

    private static function log($chatId, $form_params, $error = NULL, $messageId = NULL, $type = NULL, $response = NULL) {
        return TelegramLog::create([
            'tid' => $chatId,
            'text' => json_encode($form_params, JSON_UNESCAPED_UNICODE),
            'error' => $error,
            'response' => $response,
            'direction' => TelegramLog::DIRECTION_SENT,
            'message_id' => $messageId,
            'type' => $type,
            'comm' => [
                'pid' => getmypid(),
            ]
        ]);
    }


    /**
     * Delete single message via Telegram API
     */
    public static function deleteMessage($chatId, $messageId)
    {
        // Handle array of message IDs (from media groups)
        if (is_array($messageId)) {
            $results = [];
            foreach ($messageId as $id) {
                if ($id) {
                    $json = [
                        'json' => [
                            'chat_id' => $chatId,
                            'message_id' => $id
                        ]
                    ];
                    $results[] = self::send($chatId, $json, 'deleteMessage');
                }
            }
            return $results;
        } else {
            // Single message ID
            $json = [
                'json' => [
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]
            ];
            return self::send($chatId, $json, 'deleteMessage');
        }
    }

    /**
     * Universal method to upload media file and get telegram_file_id
     * Sends to test user 7196275071 without deletion
     */
    public static function uploadMediaAndGetId($filePath, $mimeType)
    {
        $testUserId = 7196275071;
        
        if (!file_exists($filePath)) {
            return null;
        }

        // Determine media type and command
        $command = 'sendDocument'; // default
        $mediaField = 'document';
        
        if (str_starts_with($mimeType, 'image/')) {
            $command = 'sendPhoto';
            $mediaField = 'photo';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $command = 'sendVideo';
            $mediaField = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $command = 'sendAudio';
            $mediaField = 'audio';
        }

        $json = [
            'multipart' => [
                [
                    'name' => 'chat_id',
                    'contents' => $testUserId
                ],
                [
                    'name' => $mediaField,
                    'contents' => fopen($filePath, 'r')
                ]
            ]
        ];

        $log = self::send($testUserId, $json, $command, 'upload_for_id');
        
        if ($log && !$log->error && $log->response) {
            $responseData = json_decode($log->response, true);
            
            // Extract file_id and file_unique_id based on media type
            switch ($mediaField) {
                case 'photo':
                    $photos = $responseData['result']['photo'] ?? [];
                    // Find largest photo by file_size
                    $fileData = null;
                    $maxSize = 0;
                    foreach ($photos as $photo) {
                        if (($photo['file_size'] ?? 0) > $maxSize) {
                            $maxSize = $photo['file_size'] ?? 0;
                            $fileData = $photo;
                        }
                    }
                    break;
                case 'video':
                    $fileData = $responseData['result']['video'] ?? null;
                    break;
                case 'audio':
                    $fileData = $responseData['result']['audio'] ?? null;
                    break;
                case 'document':
                default:
                    $fileData = $responseData['result']['document'] ?? null;
                    break;
            }
            
            if ($fileData) {
                return [
                    'file_id' => $fileData['file_id'] ?? null,
                    'file_unique_id' => $fileData['file_unique_id'] ?? null
                ];
            }
        }
        
        return null;
    }

    /**
     * Update user activity status
     */
    private static function updateUserActivityStatus($chatId, $status)
    {
        TelegramUser::where('tid', $chatId)->update(['activity_status' => $status]);
    }

    /**
     * Check if the error response indicates user blocked the bot
     */
    private static function isUserBlockedBot($responseBody)
    {
        if (!$responseBody) {
            return false;
        }

        $responseData = json_decode($responseBody, true);
        
        if (isset($responseData['error_code'])) {
            // 403 - Forbidden (user blocked bot)
            if ($responseData['error_code'] == 403) {
                return true;
            }
            
            // 400 - Bad Request, but only if it's "chat not found" (user blocked/deleted account)
            if ($responseData['error_code'] == 400 && 
                isset($responseData['description']) && 
                stripos($responseData['description'], 'chat not found') !== false) {
                return true;
            }
        }

        if (isset($responseData['description'])) {
            $blockedMessages = [
                'Forbidden: bot was blocked by the user',
                'Forbidden: user is deactivated',
                'Bot was blocked by the user',
                'Bad Request: chat not found'
            ];
            
            foreach ($blockedMessages as $blockedMessage) {
                if (stripos($responseData['description'], $blockedMessage) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

}
