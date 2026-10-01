<?php

namespace App\Http\Requests\Deliveries;

use App\Enums\DeliveryStatus;
use App\Http\Requests\Concerns\ParsesBusinessTime;
use App\Models\DeliveryOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A delivery status change from the order page: the status the user saw
 * (expected_status), the target status, and only the details that target
 * needs: window and truck for scheduled, a reason for cancelled. Anything
 * else is refused. Who may make which move, whether the order is still in
 * the expected status and whether the window is in the future are decided
 * by DeliveryOrderService with the order locked.
 */
class TransitionDeliveryRequest extends FormRequest
{
    use ParsesBusinessTime;

    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->order()) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $scheduling = $this->input('status') === DeliveryStatus::Scheduled->value;
        $cancelling = $this->input('status') === DeliveryStatus::Cancelled->value;

        return [
            'expected_status' => ['required', 'string', Rule::enum(DeliveryStatus::class)],
            'status' => ['required', 'string', Rule::enum(DeliveryStatus::class)],
            'scheduled_start_at' => $scheduling ? ['required', ...$this->timeRules()] : ['prohibited'],
            'scheduled_end_at' => $scheduling ? ['required', ...$this->timeRules()] : ['prohibited'],
            'assigned_truck' => $scheduling ? ['required', 'string', 'max:60'] : ['prohibited'],
            'reason' => $cancelling ? ['required', 'string', 'max:255'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_start_at.prohibited' => 'A delivery window is only sent when scheduling.',
            'scheduled_end_at.prohibited' => 'A delivery window is only sent when scheduling.',
            'assigned_truck.prohibited' => 'A truck is only sent when scheduling.',
            'reason.prohibited' => 'A reason is only sent when cancelling.',
            'reason.required' => 'Give a reason for the cancellation.',
            'assigned_truck.required' => 'Assign a truck.',
        ];
    }

    public function order(): DeliveryOrder
    {
        $order = $this->route('delivery');
        assert($order instanceof DeliveryOrder);

        return $order;
    }

    public function expectedStatus(): DeliveryStatus
    {
        return DeliveryStatus::from((string) $this->validated('expected_status'));
    }

    public function targetStatus(): DeliveryStatus
    {
        return DeliveryStatus::from((string) $this->validated('status'));
    }

    /**
     * The details for DeliveryOrderService::transition().
     *
     * @return array{scheduled_start_at?: CarbonImmutable, scheduled_end_at?: CarbonImmutable, assigned_truck?: string, cancel_reason?: string}
     */
    public function details(): array
    {
        return match ($this->targetStatus()) {
            DeliveryStatus::Scheduled => [
                'scheduled_start_at' => $this->time('scheduled_start_at'),
                'scheduled_end_at' => $this->time('scheduled_end_at'),
                'assigned_truck' => (string) $this->validated('assigned_truck'),
            ],
            DeliveryStatus::Cancelled => ['cancel_reason' => (string) $this->validated('reason')],
            default => [],
        };
    }

    /**
     * @return list<mixed>
     */
    protected function timeRules(): array
    {
        return ['string', $this->businessTimeRule()];
    }

    protected function time(string $key): CarbonImmutable
    {
        $time = $this->businessTime($key);
        assert($time !== null);

        return $time;
    }
}
