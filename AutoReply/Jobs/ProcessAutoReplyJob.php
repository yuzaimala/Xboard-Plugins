<?php

namespace Plugin\AutoReply\Jobs;

use App\Models\Ticket;
use App\Services\TicketService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessAutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $ticketId;
    protected string $userMessage;
    protected array $pluginConfig;

    public $tries = 2;
    public $timeout = 60;

    /**
     * 创建新的任务实例
     *
     * @param int $ticketId 工单ID
     * @param string $userMessage 用户消息
     * @param array $pluginConfig 插件配置
     * @return void
     */
    public function __construct(int $ticketId, string $userMessage, array $pluginConfig)
    {
        $this->onQueue('auto_reply');
        $this->ticketId = $ticketId;
        $this->userMessage = $userMessage;
        $this->pluginConfig = $pluginConfig;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('AutoReply Job 开始执行', [
            'ticket_id' => $this->ticketId,
            'message_length' => mb_strlen($this->userMessage),
            'attempt' => $this->attempts()
        ]);

        try {
            $ticket = Ticket::find($this->ticketId);
            if (!$ticket) {
                Log::warning('AutoReply Job: 工单不存在', ['ticket_id' => $this->ticketId]);
                return;
            }

            Log::info('AutoReply Job: 找到工单', [
                'ticket_id' => $this->ticketId,
                'user_id' => $ticket->user_id
            ]);

            // 检查是否需要转人工
            if ($this->shouldTransferToHuman($this->userMessage)) {
                Log::info('AutoReply Job: 检测到转人工关键词', ['ticket_id' => $this->ticketId]);
                $this->handleTransferToHuman($ticket);
                return;
            }

            // 尝试关键词回复
            if ($this->getConfig('enable_keyword_reply', true)) {
                Log::info('AutoReply Job: 尝试关键词匹配', ['ticket_id' => $this->ticketId]);
                $keywordReply = $this->matchKeywordReply($this->userMessage);
                if ($keywordReply) {
                    Log::info('AutoReply Job: 关键词匹配成功', ['ticket_id' => $this->ticketId]);
                    $this->sendAutoReply($ticket, $keywordReply, 'keyword');
                    return;
                } else {
                    Log::info('AutoReply Job: 未匹配到关键词', ['ticket_id' => $this->ticketId]);
                }
            }

            // 尝试AI回复
            if ($this->getConfig('enable_ai_reply', false)) {
                Log::info('AutoReply Job: 尝试AI回复', ['ticket_id' => $this->ticketId]);
                $aiReply = $this->getAIReply($ticket, $this->userMessage);
                if ($aiReply) {
                    Log::info('AutoReply Job: AI回复成功', ['ticket_id' => $this->ticketId]);
                    $this->sendAutoReply($ticket, $aiReply, 'ai');
                    return;
                } else {
                    Log::info('AutoReply Job: AI回复未生成', ['ticket_id' => $this->ticketId]);
                }
            } else {
                Log::info('AutoReply Job: AI回复未启用', ['ticket_id' => $this->ticketId]);
            }

            Log::info('AutoReply Job: 未找到匹配的回复', ['ticket_id' => $this->ticketId]);

        } catch (\Exception $e) {
            Log::error('AutoReply Job Error', [
                'ticket_id' => $this->ticketId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // 如果是第一次失败，重试
            if ($this->attempts() < $this->tries) {
                $this->release(30);
            } else {
                // 抛出异常触发 failed() 方法
                throw $e;
            }
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AutoReply Job 最终失败', [
            'ticket_id' => $this->ticketId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * 获取配置值
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->pluginConfig[$key] ?? $default;
    }

    /**
     * 检查是否需要转人工
     */
    protected function shouldTransferToHuman(string $message): bool
    {
        $transferKeywords = $this->getConfig('transfer_keywords', '转人工,人工客服,联系客服,人工服务');
        $keywords = array_map('trim', explode(',', $transferKeywords));

        foreach ($keywords as $keyword) {
            if (mb_stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 处理转人工
     */
    protected function handleTransferToHuman(Ticket $ticket): void
    {
        $replyMessage = "✅ 已为您转接人工客服，我们的客服人员会尽快回复您。\n\n" .
            "在等待期间，您可以：\n" .
            "• 查看我们的知识库获取常见问题解答\n" .
            "• 继续在工单中补充问题详情";

        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $ticket->id,
            $replyMessage,
            0 // 系统回复
        );

        // 如果启用了Telegram通知，发送通知给管理员
        if ($this->getConfig('enable_telegram_notify', true)) {
            $this->sendTelegramNotify($ticket);
        }

        Log::info('工单转人工处理', [
            'ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id
        ]);
    }

    /**
     * 匹配关键词回复
     */
    protected function matchKeywordReply(string $message): ?string
    {
        try {
            $rulesJson = $this->getConfig('keyword_rules', '{}');
            $rules = json_decode($rulesJson, true);

            if (!is_array($rules)) {
                Log::warning('关键词规则格式错误');
                return null;
            }

            // 按关键词长度降序排序，优先匹配长关键词
            uksort($rules, function ($a, $b) {
                return mb_strlen($b) - mb_strlen($a);
            });

            foreach ($rules as $keyword => $reply) {
                if (mb_stripos($message, $keyword) !== false) {
                    Log::info('关键词匹配成功', [
                        'keyword' => $keyword,
                        'message' => mb_substr($message, 0, 50)
                    ]);
                    return $reply;
                }
            }

        } catch (\Exception $e) {
            Log::error('关键词匹配错误', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 获取AI回复
     */
    protected function getAIReply(Ticket $ticket, string $userMessage): ?string
    {
        $apiKey = $this->getConfig('ai_api_key', '');
        if (empty($apiKey)) {
            Log::warning('AI API Key未配置');
            return null;
        }

        try {
            // 获取历史对话
            $conversationHistory = $this->getConversationHistory($ticket);

            // 构建消息
            $messages = [];

            // 添加系统提示词
            $systemPrompt = $this->getConfig('ai_system_prompt', '你是一个专业的客服助手。');
            
            // 如果启用了用户上下文注入，添加用户信息
            if ($this->getConfig('enable_user_context', true)) {
                $userContext = $this->buildUserContext($ticket);
                if ($userContext) {
                    $systemPrompt .= "\n\n" . $userContext;
                }
            }
            
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];

            // 添加历史对话
            foreach ($conversationHistory as $msg) {
                $messages[] = [
                    'role' => $msg['is_user'] ? 'user' : 'assistant',
                    'content' => $msg['message']
                ];
            }

            // 添加当前消息
            $messages[] = [
                'role' => 'user',
                'content' => $userMessage
            ];

            // 调用OpenAI API
            $timeout = (int) $this->getConfig('ai_timeout', 60);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post($this->getConfig('ai_api_base', 'https://api.openai.com/v1') . '/chat/completions', [
                'model' => $this->getConfig('ai_model', 'gpt-3.5-turbo'),
                'messages' => $messages,
                'temperature' => (float) $this->getConfig('ai_temperature', 0.7),
                'max_tokens' => (int) $this->getConfig('ai_max_tokens', 500),
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI API调用失败', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            $data = $response->json();
            $aiReply = $data['choices'][0]['message']['content'] ?? null;

            if ($aiReply) {
                Log::info('AI回复成功', [
                    'ticket_id' => $ticket->id,
                    'reply_length' => mb_strlen($aiReply)
                ]);
            }

            return $aiReply;

        } catch (\Exception $e) {
            Log::error('AI回复错误', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取对话历史
     */
    protected function getConversationHistory(Ticket $ticket): array
    {
        $maxHistory = (int) $this->getConfig('max_conversation_history', 0);
        
        // 获取所有消息
        $messages = $ticket->messages()
            ->orderBy('id', 'asc')
            ->get();

        // 如果设置了限制，只保留最后 N 条消息
        if ($maxHistory > 0 && $messages->count() > $maxHistory) {
            $messages = $messages->slice(-$maxHistory, null, true);
            Log::info('对话历史已限制', [
                'ticket_id' => $ticket->id,
                'total_messages' => $messages->count() + $maxHistory,
                'limited_to' => $maxHistory
            ]);
        }

        $history = [];
        foreach ($messages as $msg) {
            // 跳过自动回复消息
            if (mb_strpos($msg->message, '[自动回复]') !== false || 
                mb_strpos($msg->message, '[AI助手]') !== false) {
                continue;
            }

            $history[] = [
                'is_user' => $msg->user_id == $ticket->user_id,
                'message' => $msg->message
            ];
        }

        Log::info('对话历史已构建', [
            'ticket_id' => $ticket->id,
            'total_messages' => count($history),
            'max_history' => $maxHistory
        ]);

        return $history;
    }

    /**
     * 构建用户上下文信息
     */
    protected function buildUserContext(Ticket $ticket): ?string
    {
        try {
            $user = $ticket->user;
            if (!$user) {
                return null;
            }

            $currentTime = time(); // 获取当前时间戳
            $context = "## 当前用户信息\n";
            
            // 用户邮箱
            $context .= "- 用户邮箱: " . $user->email . "\n";
            
            // 套餐信息
            if ($user->plan_id && $user->plan) {
                $context .= "- 当前套餐: " . $user->plan->name . "\n";
            } else {
                $context .= "- 当前套餐: 未订阅\n";
            }
            
            // 到期时间
            if ($user->expired_at) {
                $expireDate = date('Y-m-d H:i:s', $user->expired_at);
                $isExpired = $user->expired_at < $currentTime;
                $context .= "- 到期时间: " . $expireDate . ($isExpired ? " (已过期)" : "") . "\n";
                
                // 计算剩余时间
                if (!$isExpired) {
                    $remainingSeconds = $user->expired_at - $currentTime;
                    $remainingDays = floor($remainingSeconds / 86400);
                    $remainingHours = floor(($remainingSeconds % 86400) / 3600);
                    $context .= "- 剩余时间: " . $remainingDays . "天 " . $remainingHours . "小时\n";
                }
            }
            
            // 速度限制
            if ($user->speed_limit) {
                $context .= "- 速度限制: " . $user->speed_limit . " Mbps\n";
            } else {
                $context .= "- 速度限制: 无限制\n";
            }
            
            // 流量使用情况
            if ($user->transfer_enable) {
                $transferEnable = $user->transfer_enable;
                $used = ($user->u ?? 0) + ($user->d ?? 0);
                $remaining = $transferEnable - $used;
                
                $context .= "- 总流量: " . $this->formatBytes($transferEnable) . "\n";
                $context .= "- 已使用: " . $this->formatBytes($used) . " (" . 
                    round($used / $transferEnable * 100, 2) . "%)\n";
                $context .= "- 剩余流量: " . $this->formatBytes($remaining) . "\n";
            } else {
                $context .= "- 流量: 未分配\n";
            }
            
            // 余额信息
            if ($user->balance !== null) {
                $context .= "- 账户余额: ¥" . ($user->balance / 100) . "\n";
            }
            
            // 佣金信息
            if ($user->commission_balance !== null && $user->commission_balance > 0) {
                $context .= "- 佣金余额: ¥" . ($user->commission_balance / 100) . "\n";
            }
            
            // 设备限制
            if ($user->device_limit) {
                $context .= "- 设备限制: " . $user->device_limit . " 台\n";
            }
            
            // 账户状态
            if ($user->banned) {
                $context .= "- 账户状态: 已封禁\n";
            } else {
                $context .= "- 账户状态: 正常\n";
            }
            
            // 速度限制警告
            $speedLimitWarning = (int) $this->getConfig('speed_limit_warning', 50);
            if ($speedLimitWarning > 0 && $user->speed_limit && $user->speed_limit <= $speedLimitWarning) {
                $context .= "\n## 重要提示\n";
                $context .= "⚠️ 用户速度限制为 {$user->speed_limit} Mbps，这可能就是用户反馈的卡顿和速度慢的原因。\n";
                $context .= "请主动告知用户其当前账户的速度限制，并建议升级到更高速度的套餐以获得更好的体验。\n";
            }
            
            // 添加当前时间信息
            $context .= "\n## 当前时间\n";
            $context .= "- 当前时间: " . date('Y-m-d H:i:s', $currentTime) . "\n";
            $context .= "- 当前日期: " . date('Y-m-d', $currentTime) . "\n";
            
            $context .= "\n请根据以上用户信息和当前时间提供针对性的回答和建议。";
            
            Log::info('用户上下文已构建', [
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'context_length' => mb_strlen($context)
            ]);
            
            return $context;
            
        } catch (\Exception $e) {
            Log::error('构建用户上下文失败', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 格式化字节大小
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 发送自动回复
     */
    protected function sendAutoReply(Ticket $ticket, string $reply, string $type = 'keyword'): void
    {
        // 延迟回复，模拟人工
        $delay = (int) $this->getConfig('auto_reply_delay', 2);
        if ($delay > 0) {
            sleep($delay);
        }

        // 添加前缀
        $prefix = $type === 'ai' 
            ? $this->getConfig('ai_reply_prefix', '[AI助手] ')
            : $this->getConfig('auto_reply_prefix', '[自动回复] ');

        $fullReply = $prefix . $reply;

        // 发送回复
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $ticket->id,
            $fullReply,
            0 // 系统回复，user_id为0
        );

        Log::info('自动回复已发送', [
            'ticket_id' => $ticket->id,
            'type' => $type,
            'reply_length' => mb_strlen($reply)
        ]);
    }

    /**
     * 发送Telegram通知
     */
    protected function sendTelegramNotify(Ticket $ticket): void
    {
        try {
            $telegramService = new TelegramService();
            $user = $ticket->user;
            if (!$user) {
                return;
            }

            $message = "🔔 *用户请求人工客服*\n" .
                "━━━━━━━━━━━━━━━━━━━━\n" .
                "📮 工单ID: #{$ticket->id}\n" .
                "👤 用户: `{$user->email}`\n" .
                "📝 主题: `{$ticket->subject}`\n" .
                "━━━━━━━━━━━━━━━━━━━━\n" .
                "⚠️ 请及时处理用户问题";

            $telegramService->sendMessageWithAdmin($message, true);

        } catch (\Exception $e) {
            Log::error('Telegram通知发送失败', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
