<?php

namespace App\Console\Commands;

use App\Models\CommitmentOccurrence;
use App\Models\UserPreference;
use App\Notifications\DueDateReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendDueDateReminders extends Command
{
    protected $signature = 'finance:send-due-reminders';
    protected $description = 'Queue reminder emails for commitments due within each user’s configured reminder window';

    public function handle(): int
    {
        CommitmentOccurrence::query()
            ->where('status', '!=', 'paid')
            ->with('commitment', 'user', 'payments')
            ->chunkById(100, function ($occurrences) {
                foreach ($occurrences as $occurrence) {
                    $occurrence->refresh();
                    if ($occurrence->status === 'paid') {
                        continue;
                    }

                    $preference = UserPreference::query()->where('user_id', $occurrence->user_id)->first();
                    $days = $preference?->reminders ?? 3;
                    $timezone = $preference?->timezone ?? config('app.timezone');

                    if ($occurrence->due_date->toDateString() === now($timezone)->addDays($days)->toDateString() && $occurrence->user) {
                        // Check if notification was already delivered
                        $exists = DB::table('notification_deliveries')
                            ->where('user_id', $occurrence->user_id)
                            ->where('commitment_occurrence_id', $occurrence->id)
                            ->where('type', 'due_reminder')
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        $paid = (string) $occurrence->payments->whereNull('reversed_at')->sum('amount');
                        $remaining = bcsub((string) $occurrence->amount_due, $paid, 2);
                        if (bccomp($remaining, '0.00', 2) <= 0) {
                            $occurrence->update(['status' => 'paid']);
                            continue;
                        }

                        try {
                            $occurrence->user->notify(new DueDateReminder(
                                $occurrence->commitment?->name ?? 'Your commitment',
                                $occurrence->due_date->toFormattedDateString(),
                                $remaining,
                            ));

                            DB::table('notification_deliveries')->insert([
                                'user_id' => $occurrence->user_id,
                                'commitment_occurrence_id' => $occurrence->id,
                                'type' => 'due_reminder',
                                'scheduled_date' => $occurrence->due_date->toDateString(),
                                'status' => 'sent',
                                'attempts' => 1,
                                'sent_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('Failed to send due date reminder: '.$e->getMessage(), [
                                'occurrence_id' => $occurrence->id,
                                'user_id' => $occurrence->user_id,
                            ]);
                            DB::table('notification_deliveries')->updateOrInsert(
                                [
                                    'user_id' => $occurrence->user_id,
                                    'commitment_occurrence_id' => $occurrence->id,
                                    'type' => 'due_reminder',
                                ],
                                [
                                    'scheduled_date' => $occurrence->due_date->toDateString(),
                                    'status' => 'failed',
                                    'error' => $e->getMessage(),
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                        }
                    }
                }
            });

        return self::SUCCESS;
    }
}
