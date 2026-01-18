<?php

namespace Plugin\AutoReply;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugin\AutoReply\Jobs\ProcessAutoReplyJob;

class Plugin extends AbstractPlugin
{
    protected TicketService $ticketService;
    protected ?TelegramService $telegramService = null;

    public function boot(): void
    {
        $this->ticketService = new TicketService();

        // 监听用户回复工单后的事件
        $this->listen('ticket.reply.user.after', [$this, 'handleUserReply'], 5);

        // 监听工单创建后的事件
        $this->listen('ticket.create.after', [$this, 'handleTicketCreated'], 5);
    }

    /**
     * 处理工单创建事件
     */
    public function handleTicketCreated(Ticket $ticket): void
    {
        // 获取工单内容
        $message = $ticket->messages()->latest()->first();
        if (!$message) {
            return;
        }

        $this->dispatchAutoReply($ticket, $message->message);
    }

    /**
     * 处理用户回复工单事件
     */
    public function handleUserReply(Ticket $ticket): void
    {
        // 获取最新消息
        $message = $ticket->messages()->latest()->first();
        if (!$message) {
            return;
        }

        $this->dispatchAutoReply($ticket, $message->message);
    }

    /**
     * 分发自动回复任务（异步处理）
     */
    protected function dispatchAutoReply(Ticket $ticket, string $userMessage): void
    {
        try {
            // 将任务分发到队列，避免阻塞API
            ProcessAutoReplyJob::dispatch(
                $ticket->id,
                $userMessage,
                $this->config
            );

            Log::info('自动回复任务已分发', [
                'ticket_id' => $ticket->id,
                'message_length' => mb_strlen($userMessage)
            ]);

        } catch (\Exception $e) {
            Log::error('自动回复任务分发失败', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
