$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".livestreamAnalytics").addClass("menuitem-active");

    var d = new Date(),
        n = d.getMonth(),
        y = d.getFullYear();
    $('#months option:eq(' + n + ')').prop('selected', true);
    $('#years option[value="' + y + '"]').prop('selected', true);

    var month = $('#months').val();
    var year = $('#years').val();

    $('#months , #years').on('change', function (e) {
        month = $('#months').val();
        year = $('#years').val();
        loadLivestreamChart(month, year);
    });

    // Brand tokens from assets/css/brand.css.
    const BRAND = {
        theme: '#C2410C',
        success: '#15803D',
        info: '#2563EB',
        warning: '#F59E0B',
    };

    // Streams and gift events are counts; coins are a much larger magnitude and
    // avg session is minutes — a single axis would flatten the small series to
    // the baseline, so coins and duration get their own opposite axis.
    const chart = new ApexCharts(document.querySelector('#chart-livestream'), {
        chart: { type: 'line', height: 360, toolbar: { show: false } },
        stroke: { width: 2, curve: 'smooth' },
        colors: [BRAND.theme, BRAND.info, BRAND.success, BRAND.warning],
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left' },
        series: [
            { name: 'Streams', data: [] },
            { name: 'Gift Events', data: [] },
            { name: 'Coins', data: [] },
            { name: 'Avg Session (min)', data: [] },
        ],
        markers: { size: 0, style: 'hollow' },
        xaxis: {
            type: 'datetime',
            labels: {
                datetimeUTC: false,
                format: 'dd MMM',
                style: { colors: '#6c757d', fontSize: '12px', fontFamily: 'inherit' },
                rotate: 0,
                showDuplicates: false,
            },
            axisBorder: { show: true, color: '#e0e0e0' },
            axisTicks: { show: true, color: '#e0e0e0' },
            tickAmount: 8,
        },
        yaxis: [
            {
                seriesName: 'Streams',
                title: { text: 'Streams / Gifts' },
                labels: { formatter: (v) => (v === null ? '0' : Math.round(v).toLocaleString()) },
            },
            { seriesName: 'Streams', show: false },
            {
                seriesName: 'Coins',
                opposite: true,
                title: { text: 'Coins / Minutes' },
                labels: { formatter: (v) => (v === null ? '0' : Math.round(v).toLocaleString()) },
            },
            { seriesName: 'Coins', opposite: true, show: false },
        ],
        tooltip: { x: { format: 'dd MMM yyyy' }, shared: true },
    });

    chart.render();

    function loadLivestreamChart(month, year) {
        const formdata = new FormData();
        formdata.append('month', month);
        formdata.append('year', year);

        try {
            doAjax(`${domainUrl}fetchLivestreamChartData`, formdata).then(function (response) {
                if (!response || !Array.isArray(response.data)) {
                    console.error('No response or invalid data format.');
                    return;
                }
                const rows = response.data;
                const pick = (key) => rows.map((r) => ({ x: r.date, y: r[key] }));
                chart.updateSeries([
                    { name: 'Streams', data: pick('streams') },
                    { name: 'Gift Events', data: pick('giftEvents') },
                    { name: 'Coins', data: pick('coins') },
                    { name: 'Avg Session (min)', data: pick('avgSessionMinutes') },
                ]);
            });
        } catch (error) {
            console.log('Error! : ', error.message);
            showErrorToast(error.message);
        }
    }

    loadLivestreamChart(month, year);
});
