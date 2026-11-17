<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DueDateReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $description, public readonly string $dueDate, public readonly string $amount) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Próximo vencimiento financiero')
            ->greeting('Recordatorio de vencimiento próximo')
            ->line($this->description.' vence el '.$this->dueDate.'.')
            ->line('Monto: RD$ '.$this->amount)
            ->line('Inicia sesión en Finanzas Clara para revisar tu calendario.');
    }

    public function toArray(object $notifiable): array
    {
        return ['description' => $this->description, 'due_date' => $this->dueDate, 'amount' => $this->amount];
    }
}
