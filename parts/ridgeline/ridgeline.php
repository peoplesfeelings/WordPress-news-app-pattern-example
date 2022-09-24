<?php


?>

<script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.19.3/dist/jquery.validate.js"></script>
<script src="https://v2.vuejs.org/js/vue.min.js"></script>
<link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/css/news-app.css" />


<div class="form-station-wrapper">
    <form action="" name="station-input">
        <label for="zip">Zip</label>
        <input type="text" name="zip" autofocus value="64030" />
        <label for="distance">Radius for weather stations (km)</label>
        <input type="text" name="distance" value="45" />
        <label for="duration">Time (yrs)</label>
        <input type="text" name="duration" value="20" />
        <input type="submit">
    </form>

    <div id="stations-outer" class="hidden-panel">
        <div v-if="!stations.length">
            <div class="spinner-holder"><i class="fa fa-spinner fa-pulse"></i></div>
        </div>
        <div v-if="stations.length">
            <h3>Weather stations</h3>
            <div v-for="station in stations" :key="station.id" class="station" :class="{ sel : station.id == selected }" @click="station.id != selected && select(station.id)">
                <h4>{{ station.name }}</h4>
                <p>{{station.consecutive}} records</p>
            </div>
        </div>
    </div>
</div>

<div id="error-place"></div>

<div class="chart-panel hidden-panel">
    <div id="chart-spinner" v-if="show">
        <div class="spinner-holder"><i class="fa fa-spinner fa-pulse"></i></div>
    </div>

    <div id="chart-container"></div>
</div>


<script type="module">
    const baseUrl = '<?php echo get_site_url(); ?>';
    let allStationsData = {};

    // observable stuff
    import define from "<?php echo get_template_directory_uri(); ?>/news-app/parts/ridgeline/notebook.js";
    import {
        Runtime,
        Library,
        Inspector
    } from "https://cdn.jsdelivr.net/npm/@observablehq/runtime@4/dist/runtime.js";
    const containerEl = document.querySelector("#chart-container");
    const runtime = new Runtime();
    const main = runtime.module(define, notebookVariable => {
        if (notebookVariable === "chart") {
            return new Inspector(containerEl);
        }
    });
    main.define('wid', [], containerEl.clientWidth);
    window.addEventListener('resize', function() {
        main.redefine('wid', [], containerEl.clientWidth);
    });

    // vue stuff
    var vStations = new Vue({
        el: '#stations-outer',
        data: {
            stations: [],
            selected: ''
        },
        methods: {
            select(stationId) {
                this.selected = stationId;
                main.redefine("dataFromWeatherStation", [], allStationsData[stationId]);
            }
        }
    })
    var vChartSpinner = new Vue({
        el: '#chart-spinner',
        data: {
            show: false
        }
    })

    $(() => {
        // form stuff
        $("form[name='station-input']").validate({
            rules: {
                zip: {
                    required: true,
                    minlength: 5,
                    maxlength: 5,
                    digits: true
                },
                distance: {
                    required: true,
                    minlength: 2,
                    maxlength: 4,
                    digits: true
                },
                duration: {
                    required: true,
                    minlength: 2,
                    maxlength: 3,
                    digits: true
                }
            },
            messages: {
                zip: {
                    required: "ZIP is required",
                    minlength: "ZIP must be 5 characters",
                    maxlength: "ZIP must be 5 characters",
                    digits: "Integer characters only"
                },
                distance: {
                    required: "Distance is required",
                    minlength: "Distance must be at least 10",
                    maxlength: "Distance must be less than 5 characters",
                    digits: "Integer characters only"
                },
                duration: {
                    required: "Duration is required",
                    minlength: "Duration must be at least 10",
                    maxlength: "Duration must be less than 4 characters",
                    digits: "Integer characters only"
                }
            },
            submitHandler: form => {
                handleSubmit();
                event.preventDefault();
            }
        });
    });


    function handleSubmit() {
        unsetData();
        $('#stations-outer').addClass('active');
        $('.chart-panel').addClass('active');
        $('#error-place').text("");
        main.redefine('wid', [], containerEl.clientWidth);

        let zip = $('input[name="zip"]').val();
        let distance = $('input[name="distance"]').val();
        let duration = $('input[name="duration"]').val();

        const url = baseUrl + '/wp-json/historicalWeather/getData/zip=' + zip + '/distance=' + distance + '/duration=' + duration;

        $.ajax({
            url: url,
            type: 'get',
            dataType: 'JSON',
            timeout: 2 * 60 * 1000,
            success: function(response) {
                response = JSON.parse(response);
                console.log('resp', response);
                vStations.stations = addCountToStations(response.stations.results, response.data);
                let bestKey = findBest(response.data);
                vStations.selected = bestKey;
                main.redefine("dataFromWeatherStation", [], response.data[bestKey]);
                allStationsData = response.data;
                vChartSpinner.show = false;
            },
            error: res => {
                if ("statusText" in res && res.statusText == "timeout") {
                    handleTimeout("response: " + JSON.stringify(res));
                }
            }
        });
    }

    function handleTimeout(info) {
        $('#stations-outer').removeClass('active');
        $('.chart-panel').removeClass('active');
        $('#error-place').text("API request timed out. Try again later.");
    }

    function unsetData() {
        vStations.stations = [];
        main.redefine("dataFromWeatherStation", [], []);
        vChartSpinner.show = true;
    }

    function findBest(data) {
        let longestCount = 0;
        let longestKey = '';
        Object.keys(data).forEach(key => {
            if (data[key].length > longestCount) {
                longestCount = data[key].length;
                longestKey = key;
            }
        })
        return longestKey;
    }

    function addCountToStations(stations, data) {
        stations.forEach(station => {
            station.consecutive = data[station.id].length
        });
        return stations;
    }

</script>
