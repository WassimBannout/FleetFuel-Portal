<?php

namespace App\Http\Requests\Deliveries;

use App\Http\Requests\Concerns\ParsesBusinessTime;
use App\Http\Requests\Fleet\ResolvesCompany;
use App\Models\DeliveryOrder;
use App\Rules\DecimalString;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new diesel delivery order from the web form. The company comes from the
 * manager's account, or an admin picks an active one. Status, truck, price
 * and delivery time are never accepted: the order always starts pending,
 * and DeliveryOrderService checks the preferred window (future, end after
 * start). Times are typed in Beirut business time.
 */
class StoreDeliveryOrderRequest extends FormRequest
{
    use ParsesBusinessTime;
    use ResolvesCompany;

    public function authorize(): bool
    {
        return $this->actor()->can('create', DeliveryOrder::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'address' => ['required', 'string', 'max:500'],
            'governorate' => ['required', 'string', 'max:80'],
            // DECIMAL(10,2), and more than zero.
            'liters' => ['required', new DecimalString(8, allowZero: false)],
            'preferred_start_at' => ['required', ...$this->timeRules()],
            'preferred_end_at' => ['required', ...$this->timeRules()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->companyMessages();
    }

    /**
     * @return array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}
     */
    public function details(): array
    {
        return [
            'address' => (string) $this->validated('address'),
            'governorate' => (string) $this->validated('governorate'),
            'liters' => (string) $this->validated('liters'),
            'preferred_start_at' => $this->time('preferred_start_at'),
            'preferred_end_at' => $this->time('preferred_end_at'),
        ];
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
