@use('App\Support\Display')
@use('App\Support\Redact')

{{-- Expects $transactions (with company, station, product, fuelCard loaded), $showCompany and $showStation. --}}
@if ($transactions->isEmpty())
    <p class="text-body-secondary">No purchases yet.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle bg-body">
            <thead>
                <tr>
                    <th scope="col">Time (Beirut)</th>
                    @if ($showCompany)
                        <th scope="col">Company</th>
                    @endif
                    @if ($showStation)
                        <th scope="col">Station</th>
                    @endif
                    <th scope="col">Card</th>
                    <th scope="col">Product</th>
                    <th scope="col" class="text-end">Liters</th>
                    <th scope="col" class="text-end">Amount (USD)</th>
                    <th scope="col" class="text-end">Amount (LBP)</th>
                    <th scope="col"><span class="visually-hidden">Details</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transactions as $transaction)
                    <tr>
                        <td class="text-nowrap">{{ Display::businessTime($transaction->transacted_at) }}</td>
                        @if ($showCompany)
                            <td>{{ $transaction->company->name }}</td>
                        @endif
                        @if ($showStation)
                            <td>{{ $transaction->station->name }}</td>
                        @endif
                        <td class="font-monospace">{{ Redact::cardNumber($transaction->fuelCard->card_no) }}</td>
                        <td>{{ $transaction->product->name }}</td>
                        <td class="text-end">{{ Display::decimal($transaction->liters) }}</td>
                        <td class="text-end">{{ Display::decimal($transaction->amount_usd) }}</td>
                        <td class="text-end">{{ Display::decimal($transaction->amount_lbp) }}</td>
                        <td><a href="{{ route('transactions.show', $transaction->id) }}">Details<span class="visually-hidden"> of purchase {{ $transaction->id }}</span></a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
