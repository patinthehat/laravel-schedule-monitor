<?php


namespace Spatie\ScheduleMonitor\Support\ScheduledTasks\Tasks;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;
use Lorisleiva\CronTranslator\CronParsingException;
use Lorisleiva\CronTranslator\CronTranslator;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

abstract class Task
{
    protected Event $event;

    protected string $uniqueId;

    protected ?MonitoredScheduledTask $monitoredScheduledTask = null;

    abstract public static function canHandleEvent(Event $event): bool;

    public function __construct(Event $event)
    {
        $this->event = $event;

        $this->uniqueId = (string)Str::uuid();

        if (! empty($this->name())) {
            $this->monitoredScheduledTask = MonitoredScheduledTask::findByName($this->name());
        }
    }

    public function uniqueId(): string
    {
        return $this->uniqueId;
    }

    public function name(): ?string
    {
        if (! isset($this->event->monitorName)) {
            return $this->defaultName();
        }

        return $this->event->monitorName ?? $this->defaultName();
    }

    public function shouldMonitor(): bool
    {
        if (! isset($this->event->doNotMonitor)) {
            return true;
        }

        return ! $this->event->doNotMonitor;
    }

    public function isBeingMonitored(): bool
    {
        return ! is_null($this->monitoredScheduledTask);
    }

    public function isBeingMonitoredAtOhDear(): bool
    {
        if (! $this->isBeingMonitored()) {
            return false;
        }

        return ! empty($this->monitoredScheduledTask->ping_url);
    }

    public function previousRunAt(): Carbon
    {
        $dateTime = CronExpression::factory($this->cronExpression())->getPreviousRunDate(now());

        return Carbon::instance($dateTime);
    }

    public function nextRunAt(Carbon $now = null): Carbon
    {
        $dateTime = CronExpression::factory($this->cronExpression())->getNextRunDate($now ?? now());

        return Carbon::instance($dateTime);
    }

    public function lastRunStartedAt(): ?Carbon
    {
        return optional($this->monitoredScheduledTask)->last_started_at;
    }

    public function lastRunFinishedAt(): ?Carbon
    {
        return optional($this->monitoredScheduledTask)->last_finished_at;
    }

    public function lastRunFailedAt(): ?Carbon
    {
        return optional($this->monitoredScheduledTask)->last_failed_at;
    }

    public function lastRunSkippedAt(): ?Carbon
    {
        return optional($this->monitoredScheduledTask)->last_skipped_at;
    }

    public function lastRunFinishedTooLate(): bool
    {
        if (! $this->isBeingMonitored()) {
            return false;
        }

        $lastFinishedAt = $this->lastRunFinishedAt()
            ? $this->lastRunFinishedAt()
            : $this->monitoredScheduledTask->created_at;

        $expectedNextRunStart = $this->nextRunAt($lastFinishedAt->subSecond());

        $shouldHaveFinishedAt = $expectedNextRunStart->addMinutes($this->graceTimeInMinutes());

        return $shouldHaveFinishedAt->isPast();

        /*
        if ($this->monitoredScheduledTask->created_at->isAfter($shouldHaveFinishedAt)) {
            return false;
        }

        if ($shouldHaveFinishedAt->isFuture()) {
            return false;
        }

        if (! $this->lastRunFinishedAt()) {
            return true;
        }

        if ($this->lastRunFinishedAt()->between($this->previousRunAt(), $this->nextRunAt())) {
            return false;
        }

        return now()->isAfter($shouldHaveFinishedAt);
        */
    }

    public function lastRunFailed(): bool
    {
        if (! $this->isBeingMonitored()) {
            return false;
        }

        if (! $lastRunFailedAt = $this->lastRunFailedAt()) {
            return false;
        }

        if (! $lastRunStartedAt = $this->lastRunStartedAt()) {
            return true;
        }

        return $lastRunFailedAt->isAfter($lastRunStartedAt->subSecond());
    }



    abstract public function defaultName(): ?string;

    public function graceTimeInMinutes()
    {
        return $this->event->graceTimeInMinutes ?? 5;
    }

    abstract public function type(): string;

    public function cronExpression(): string
    {
        return $this->event->getExpression();
    }

    public function humanReadableCron(): string
    {
        try {
            return CronTranslator::translate($this->cronExpression());
        } catch (CronParsingException $exception) {
            return $this->cronExpression();
        }
    }
}