$(document).ready(function () {
    $(".side-nav-item").removeClass("menuitem-active");
    $(".index").addClass("menuitem-active");


        var d = new Date(),
        n = d.getMonth(),
        y = d.getFullYear();
        $('#months option:eq('+n+')').prop('selected', true);
        $('#years option[value="'+y+'"]').prop('selected', true);

        var month = $('#months').val();
        var year = $('#years').val();


        $('#months , #years').on('change', function(e){
            month = $('#months').val();
            year = $('#years').val();

            initDashboardChart(month, year);
            loadEngagementChart(month, year);
            loadRevenueChart(month, year);

        })

        function initDashboardChart(month, year) {

            formdata = new FormData();
            formdata.append('month', month);
            formdata.append('year', year);

            var url =
            `${domainUrl}fetchChartData`;
            try {
                doAjax(url, formdata).then(function (response){
                    if (!response || !Array.isArray(response.data)) {
                        console.error("No response or invalid data format.");
                        return;
                    }
                //   Loading Datatable
                // Format the data for chart and remove duplicates
                const userCountData = [];
                const postCountData = [];
                const dauCountData = [];

                response.data.forEach(item => {
                    const date = new Date(item.date);
                    const timestamp = date.getTime();

                    userCountData.push({
                        x: item.date, // Ensure it's a timestamp
                        y: item.usersCount,
                    });
                    postCountData.push({
                        x: item.date, // Ensure it's a timestamp
                        y: item.postsCount,
                    });
                    dauCountData.push({
                        x: item.date, // Ensure it's a timestamp
                        y: item.dauCount,
                    });


                });

                chart.updateSeries([{ name: 'DAU', data: dauCountData },{ name: 'New Users', data: userCountData },{ name: 'New Posts', data: postCountData }]);

                });
            } catch (error) {
            console.log('Error! : ', error.message);
                showErrorToast(error.message);
            }
        };

            const chartElement = document.querySelector("#chart-dashboard");

            const chart = new ApexCharts(chartElement, {
                chart: { type: 'area', height: 350 },
                stroke: { width: 3, curve: 'smooth' },
                // Brand tokens from assets/css/brand.css (theme / success / info).
                colors: ['#C2410C', '#15803D', '#2563EB'],
                dataLabels: {
                    enabled: false,
                    // formatter: function (value) {
                    //     // Return null if value is 0 to hide the label
                    //     return value === 0 ? '' : `${$currency} ${value.toLocaleString()}`;
                    // }
                },
                series: [{ name: 'DAU', data: [] },{ name: 'New Users', data: [] },{ name: 'New Posts', data: [] }],
                markers: { size: 0, style: 'hollow' },
                xaxis: {
                    type: 'datetime',
                    // Single labels block. This was declared twice, and the
                    // second literal silently discarded datetimeUTC/format/
                    // rotate/showDuplicates from the first.
                    labels: {
                        datetimeUTC: false,
                        format: 'dd MMM yyyy', // Format the labels as Day-Month-Year
                        style: { colors: '#6c757d', fontSize: '12px', fontFamily: 'inherit' },
                        rotate: 0, // Keep labels horizontal
                        showDuplicates: false, // Ensures no duplicate labels
                        padding: 10,
                    },
                    axisBorder: { show: true, color: '#e0e0e0' },
                    axisTicks: { show: true, color: '#e0e0e0' },
                    tickAmount: 10, // Dynamically adjusted based on data points
                    min: undefined,
                    max: undefined,
                },
                yaxis: {
                    labels: {
                        formatter: (value) => value.toLocaleString(), // Format values with commas
                    },
                },
                tooltip: {
                    x: { format: 'dd MMM yyyy' },
                    y: {
                        formatter: (value) => `${value.toLocaleString()}`,
                    },
                },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.7, opacityTo: 0, stops: [0, 100] },
                },
            });

            chart.render();

            initDashboardChart(month, year);

            // ---- Engagement + revenue -------------------------------------
            // Brand tokens from assets/css/brand.css.
            const BRAND = {
                theme: '#C2410C',
                success: '#15803D',
                info: '#2563EB',
                warning: '#F59E0B',
                danger: '#FF2D55',
            };

            // Both charts share the dashboard chart's shape, so the common
            // options live in one place rather than being pasted twice.
            function timeSeriesOptions(colors, seriesNames, valueFormatter) {
                return {
                    chart: { type: 'area', height: 320, toolbar: { show: false } },
                    stroke: { width: 2, curve: 'smooth' },
                    colors: colors,
                    dataLabels: { enabled: false },
                    series: seriesNames.map((name) => ({ name: name, data: [] })),
                    markers: { size: 0, style: 'hollow' },
                    legend: { position: 'top', horizontalAlign: 'left' },
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
                    yaxis: { labels: { formatter: valueFormatter } },
                    tooltip: { x: { format: 'dd MMM yyyy' }, y: { formatter: valueFormatter } },
                    fill: {
                        type: 'gradient',
                        gradient: { shadeIntensity: 1, opacityFrom: 0.5, opacityTo: 0, stops: [0, 100] },
                    },
                };
            }

            const countFormatter = (value) => (value === null || value === undefined ? '0' : value.toLocaleString());

            const engagementChart = new ApexCharts(
                document.querySelector('#chart-engagement'),
                timeSeriesOptions(
                    [BRAND.danger, BRAND.info, BRAND.success, BRAND.warning],
                    ['Likes', 'Comments', 'Follows', 'Saves'],
                    countFormatter
                )
            );
            engagementChart.render();

            // Currency prefix is filled in from the API response, which reads it
            // from tbl_settings rather than hardcoding a symbol here.
            let revenueCurrency = '';
            const revenueChart = new ApexCharts(
                document.querySelector('#chart-revenue'),
                timeSeriesOptions(
                    [BRAND.success, BRAND.info, BRAND.theme, BRAND.danger],
                    ['Fan spend', 'Creator earnings', 'Platform commission', 'Payouts'],
                    (value) => {
                        if (value === null || value === undefined) return '0';
                        return `${revenueCurrency}${value.toLocaleString()}`;
                    }
                )
            );
            revenueChart.render();

            function loadSeries(endpoint, month, year, onData) {
                const formdata = new FormData();
                formdata.append('month', month);
                formdata.append('year', year);
                try {
                    doAjax(`${domainUrl}${endpoint}`, formdata).then(function (response) {
                        if (!response || !response.data) {
                            console.error(`No response for ${endpoint}.`);
                            return;
                        }
                        onData(response.data);
                    });
                } catch (error) {
                    console.log('Error! : ', error.message);
                    showErrorToast(error.message);
                }
            }

            function loadEngagementChart(month, year) {
                loadSeries('fetchEngagementChartData', month, year, function (rows) {
                    if (!Array.isArray(rows)) return;
                    const pick = (key) => rows.map((r) => ({ x: r.date, y: r[key] }));
                    engagementChart.updateSeries([
                        { name: 'Likes', data: pick('likes') },
                        { name: 'Comments', data: pick('comments') },
                        { name: 'Follows', data: pick('follows') },
                        { name: 'Saves', data: pick('saves') },
                    ]);
                });
            }

            function loadRevenueChart(month, year) {
                loadSeries('fetchRevenueChartData', month, year, function (payload) {
                    const rows = payload.series;
                    if (!Array.isArray(rows)) return;
                    revenueCurrency = payload.currency ? `${payload.currency} ` : '';
                    const pick = (key) => rows.map((r) => ({ x: r.date, y: r[key] }));
                    revenueChart.updateSeries([
                        { name: 'Fan spend', data: pick('fanSpend') },
                        { name: 'Creator earnings', data: pick('creatorEarnings') },
                        { name: 'Platform commission', data: pick('commission') },
                        { name: 'Payouts', data: pick('payouts') },
                    ]);
                    if (payload.owed) {
                        $('#revenueOwed').text(
                            `Owed now: ${revenueCurrency}${Number(payload.owed.earnings).toLocaleString()} unpaid creator earnings · ` +
                            `${revenueCurrency}${Number(payload.owed.coins).toLocaleString()} in fans' unspent coins`
                        );
                    }
                });
            }

            loadEngagementChart(month, year);
            loadRevenueChart(month, year);

});
