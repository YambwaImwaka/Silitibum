@extends('include.app')
@section('script')
<script src="{{ asset('assets/script/livestream_analytics.js') }}"></script>
@endsection
@section('content')

<div class="row">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="tilebox-one">
                    <i class='uil uil-video float-end'></i>
                </div>
                <h5 class="text-uppercase mt-0">{{__('Live Now')}}</h5>
                <h2 class="my-2" id="stat-live-now">{{$liveNow}}</h2>
                <span class="text-muted badge border">
                    <span class="text-success fw-medium me-1 fs-6">{{$streamsTotal}}</span>
                    <span class="text-nowrap fw-medium fs-6">{{__('Total Streams')}}</span>
                </span>
                <span class="text-muted badge border">
                    <span class="text-info fw-medium me-1 fs-6">{{$battlesTotal}}</span>
                    <span class="text-nowrap fw-medium fs-6">{{__('Battles')}}</span>
                </span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="tilebox-one">
                    <i class='uil uil-coins float-end'></i>
                </div>
                <h5 class="text-uppercase mt-0">{{__('Coins Earned In Streams')}}</h5>
                <h2 class="my-2" id="stat-live-coins">{{number_format($coinsLifetime)}}</h2>
                <span class="text-muted badge border">
                    <span class="text-nowrap fw-medium fs-6">{{__('Lifetime')}}</span>
                </span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="tilebox-one">
                    <i class='uil uil-gift float-end'></i>
                </div>
                <h5 class="text-uppercase mt-0">{{__('Gift Events')}}</h5>
                <h2 class="my-2" id="stat-live-gifts">{{number_format($giftEventsTotal)}}</h2>
                <span class="text-muted badge border">
                    <span class="text-nowrap fw-medium fs-6">{{__('In Livestreams')}}</span>
                </span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="tilebox-one">
                    <i class='uil uil-clock float-end'></i>
                </div>
                <h5 class="text-uppercase mt-0">{{__('Avg Session')}}</h5>
                <h2 class="my-2" id="stat-live-session">
                    {{ $avgSessionSeconds > 0 ? gmdate($avgSessionSeconds >= 3600 ? 'H:i:s' : 'i:s', $avgSessionSeconds) : '—' }}
                </h2>
                <span class="text-muted badge border">
                    <span class="text-nowrap fw-medium fs-6">{{__('Per Viewer, Ended Sessions')}}</span>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="card">
        <div class="card-header border-bottom d-flex align-items-center">
            <h4 class="header-title me-auto">{{__('Livestream Activity')}}</h4>
            <select class="picker me-1" name="month" id="months">
                <option value="01">January</option>
                <option value="02">February</option>
                <option value="03">March</option>
                <option value="04">April</option>
                <option value="05">May</option>
                <option value="06">June</option>
                <option value="07">July</option>
                <option value="08">August</option>
                <option value="09">September</option>
                <option value="10">October</option>
                <option value="11">November</option>
                <option value="12">December</option>
            </select>
            <select class="picker" name="year" id="years">
                @foreach (range(now()->year - 2, now()->year + 1) as $selectableYear)
                    <option value="{{ $selectableYear }}">{{ $selectableYear }}</option>
                @endforeach
            </select>
        </div>
        <div class="card-body">
            <div dir="ltr">
                <div id="chart-livestream" class="apex-charts"></div>
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="card">
        <div class="card-header border-bottom">
            <h4 class="header-title mb-0">{{__('Top Hosts By Coins Earned')}}</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-centered table-hover w-100 mb-0">
                    <thead>
                        <tr>
                            <th>{{__('Host')}}</th>
                            <th class="text-end">{{__('Coins')}}</th>
                            <th class="text-end">{{__('Streams')}}</th>
                            <th class="text-end">{{__('Followers Gained')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topHosts as $host)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        @if ($host->profile_photo)
                                            <img src="{{ $baseUrl . $host->profile_photo }}"
                                                class="rounded-circle me-2" width="36" height="36"
                                                alt="{{ $host->username }}">
                                        @endif
                                        <div>
                                            <div class="fw-medium">{{ $host->fullname }}</div>
                                            <div class="text-muted fs-6">{{ '@' . $host->username }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end fw-medium">{{ number_format($host->coins) }}</td>
                                <td class="text-end">{{ number_format($host->streams) }}</td>
                                <td class="text-end">{{ number_format($host->followers_gained ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">
                                    {{__('No livestream data yet.')}}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
