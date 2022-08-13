/*
    based on:
    https://observablehq.com/@d3/ridgeline-plot
*/
export default function define(runtime, observerFactory) {
    const main = runtime.module();
    main.variable(observerFactory("chart")).define("chart", ["lineBottom", "xAxis", "yAxis", "data", "depthAngleXScale", "depthAngleYScale", "area", "line", "svg", "lineLeft", "lineRight", 'show', "DOM", "d3"], function (lineBottom, xAxis, yAxis, data, depthAngleXScale, depthAngleYScale, area, line, svg, lineLeft, lineRight, show, DOM, d3) {
        const visible = svg.append("g")
            .attr('id', 'visible-g');

        visible.append("g")
            .call(yAxis);

        const group = visible.append("g")
            .selectAll("g")
            .data(data.years)
            .join("g")
            .attr("transform", d => `translate(${depthAngleXScale(d.name)},${depthAngleYScale(d.name) + 1})`);

        group.append("path")
            .attr("fill", "rgba(255,255,255,1)")
            .attr("d", d => area(d.values));

        group.append("path")
            .attr("fill", "none")
            .attr("stroke", "black")
            .attr("d", d => line(d.values));

        group.append("path")
            .attr("fill", "none")
            .attr("stroke", "black")
            .attr("d", d => lineBottom(d.values));

        group.append("path")
            .attr("fill", "none")
            .attr("stroke", "black")
            .attr("d", d => lineLeft(d.values));

        group.append("path")
            .attr("fill", "none")
            .attr("stroke", "black")
            .attr("d", d => lineRight(d.values));

        visible.append("g")
            .call(xAxis);

        // put overlay on top of new elements
        d3.select('#pan-overlay').raise();

        /*
            svg is its own cell, because chart reruns every time panning occurs. using d3.zoom panning for 3d shearing
            works if the zoom code is in a separate cell that is not rerunning while the panning is occurring.

            we return the svg node from this cell, for the page, for simplicity. returning the svg node from another cell
            would require more code, but this works.
        */

        return show ? svg.node() : DOM.text('')
    });
    main.variable().define("config", function () {
        return ({
            margin: {
                top: 140, right: 60, bottom: 40, left: 85
            },
            lineChartHeight: 140,
            yearHeight: 17,
            joystickXExtent: 100,
            startingJoystick: [0.7, 0.8]
        })
    });
    main.variable().define("height", ["config", "yearsHeight"], function (config, yearsHeight) {
        /* 
            full svg height
        */
        return (yearsHeight + config.margin.top + config.margin.bottom)
    });
    main.variable().define("yearsHeight", ["data", "config"], function (data, config) {
        /* 
            height of space for arraying years
        */
        return (data.years.length * config.yearHeight)
    });
    main.variable().define("lineChartXScale", ["d3", "data", "config", "wid"], function (d3, data, config, wid) {
        return (
            d3.scaleLinear()
                .domain(d3.extent(data.months))
                .range([config.margin.left, wid - config.margin.right])
        )
    });
    main.variable().define("lineChartYScale", ["valueRange", "d3", "config"], function (valueRange, d3, config) {
        /* 
            the y of the line graphs
        */
        return (
            d3.scaleLinear()
                .domain([valueRange.low, valueRange.high]).nice()
                .range([0, -config.lineChartHeight])
        )
    });
    main.variable().define("verticalCenter", ["config", "yearsHeight"], function (config, yearsHeight) {
        /* 
            central pivot point of the joystick-controlled shearing of the ridgeline
        */
        return config.margin.top + (yearsHeight / 2)
    });
    main.variable().define("depthAngleXScale", ["d3", "data", "joystickXUsable"], function (d3, data, joystickXUsable) {
        /*
            adjustment for 3D control
            this x offset is determined by the year and the 3d control
            
            depth angle is the shear angle
        */
        return (
            d3.scalePoint()
                .domain(data.years.map(d => d.name))
                .range([joystickXUsable * -1, joystickXUsable])
        )
    });
    main.variable().define("depthAngleYScale", ["d3", "data", "joystickYUsable", "verticalCenter"], function (d3, data, joystickYUsable, verticalCenter) {
        /* 
            years
            ascending order, top to bottom 

            depth angle is the shear angle
        */
        return (
            d3.scalePoint()
                .domain(data.years.map(d => d.name))
                .range([verticalCenter - joystickYUsable, verticalCenter + joystickYUsable])
        )
    });
    main.variable().define("xAxis", ["d3", "joystickXUsable", "joystickYUsable", "verticalCenter", "wid", "config"], function (d3, joystickXUsable, joystickYUsable, verticalCenter, wid, config) {
        /*
            returns a function that
                takes a g
                gives it a transform
                calls d3.axisBottom(x)
                    x being a d3 time scale
        */

        function getYTranslate() {
            return verticalCenter + joystickYUsable
        }
        return (
            g => g
                .attr("transform", `translate(${joystickXUsable},${getYTranslate()})`)
                .call(d3.axisBottom(d3.scaleTime().domain([new Date(2000, 0, 1), new Date(2000, 11, 1)]).range([config.margin.left, wid - config.margin.right])).tickFormat(d3.timeFormat("%b %e")))
                .call(g => g.select(".domain").remove())
                .selectAll("text")
                .attr("style", "transform: translateX(15px) translateY(20px) rotate(80deg);")
        )
    });
    main.variable().define("yAxis", ["config", "d3", "depthAngleXScale", "depthAngleYScale"], function (config, d3, depthAngleXScale, depthAngleYScale) {
        return (

            g => g
                .attr("transform", `translate(${config.margin.left},0)`)
                .call(d3.axisLeft(depthAngleYScale).tickSize(0).tickPadding(4))
                .call(g => g.select(".domain").remove())
                .selectAll("text")
                .attr("x", depthAngleXScale)
                .text(d => { return d + ' -'; })
        )
    });
    main.variable().define("area", ["d3", "lineChartXScale", "data", "lineChartYScale"], function (d3, lineChartXScale, data, lineChartYScale) {
        return (
            d3.area()
                .defined(d => !!d)
                .curve(d3.curveLinear)
                .x((d, i) => lineChartXScale(data.months[i]))
                .y0(0)
                .y1(d => lineChartYScale(d))
        )
    });
    main.variable().define("line", ["area"], function (area) {
        return (area.lineY1())
    });
    main.variable().define("lineBottom", ["d3", "lineChartXScale"], function (d3, lineChartXScale) {
        return (lineChartData => {
            const twoPoints = [[lineChartXScale(0), 0], [lineChartXScale(11), 0]];
            const line = d3.line()
                .curve(d3.curveLinear);
            return line(twoPoints);
        })
    });
    main.variable().define("lineLeft", ["d3", "lineChartYScale", "lineChartXScale"], function (d3, lineChartYScale, lineChartXScale) {
        return (lineChartData => {
            // get data index where values start (non-null data values for the furthest line chart may start after the first month)
            const firstDefinedInd = lineChartData.findIndex(d => !!d);
            const twoPoints = [[lineChartXScale(firstDefinedInd), 0], [lineChartXScale(firstDefinedInd), lineChartYScale(lineChartData[firstDefinedInd])]];
            const line = d3.line()
                .curve(d3.curveLinear);
            return line(twoPoints);
        })
    });
    main.variable().define("lineRight", ["d3", "lineChartYScale", "lineChartXScale"], function (d3, lineChartYScale, lineChartXScale) {
        return (lineChartData => {
            // get data index where values end (non-null data values for the nearest line chart may end before 12th month)
            const LastDefinedInd = lineChartData.findLastIndex(d => !!d);
            const twoPoints = [[lineChartXScale(LastDefinedInd), 0], [lineChartXScale(LastDefinedInd), lineChartYScale(lineChartData[LastDefinedInd])]];
            const line = d3.line()
                .curve(d3.curveLinear);
            return line(twoPoints);
        })
    });
    main.variable().define("data", ["transform", "dataFromWeatherStation"], async function (transform, dataFromWeatherStation) {
        const theData = transform(dataFromWeatherStation);
        return theData;
    });
    main.variable().define("show", ["dataFromWeatherStation"], async function (dataFromWeatherStation) {
        // hide the chart when data is set to empty array
        if (dataFromWeatherStation.length > 0) {
            return true;
        }
        return false;
    });
    main.variable().define("transform", async function () {
        // transform from format as assembled from api calls to format as expected by chart
        return (dfws) => {
            const obj = {};
            const years = [];
            let baseValues = [null, null, null, null, null, null, null, null, null, null, null, null];

            // transform by year
            dfws.forEach((rec) => {
                let date = new Date(rec.date);
                let yearStr = date.getFullYear() + "";
                if (!years.find((y) => y.name == yearStr)) {
                    years.push({ name: yearStr, values: [...baseValues] });
                }
            });

            years.forEach((year) => {
                dfws.forEach((rec) => {
                    let date = new Date(rec.date);
                    if (date.getFullYear() + '' == year.name) {
                        year.values[date.getMonth()] = rec.value
                    }
                });
            });

            /* 
                dimensions
                    - horizontal of a line chart
                    - vertical of a line chart 
                    - faux 3d dimension on which is arrayed the set of line charts
            */

            obj.years = years;
            obj.months = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

            return obj;
        }
    });
    main.variable().define("dataFromWeatherStation", function () {
        // as assembled from api calls in the backend code
        return []
    });
    main.variable().define("valueRange", ["d3", "dataFromWeatherStation"], async function (d3, dataFromWeatherStation) {
        /* 
            the idea of the ridgeline is for the user to compare the line charts. so we want all line charts shown to have 
            the same max and min values of the vertical axis.
        */

        return {
            high: d3.max(dataFromWeatherStation, d => d.value),
            low: d3.min(dataFromWeatherStation, d => d.value)
        }
    });
    main.variable().define("joystickXUsable", ["joystickRaw", "config"], function (joystickRaw, config) {
        // raw joystick x as a positive or negative value centered around 0, within specified range in either direction
        const pos = config.joystickXExtent * joystickRaw[0];
        const posZeroCentered = pos - (config.joystickXExtent / 2);
        return posZeroCentered;
    });
    main.variable().define("joystickYUsable", ["joystickRaw", "yearsHeight"], function (joystickRaw, yearsHeight) {
        // raw joystick y as a positive or negative value centered around a 0, with a specified range in either direction
        const pos = joystickRaw[1] * yearsHeight;
        const posZeroCentered = pos - (yearsHeight / 2);
        return posZeroCentered;
    });
    main.variable().define("mutable joystickRaw", ["Mutable", "config"], function (Mutable, config) {
        /*
            the "[mutable] joystickRaw" value represents the state of the drag-shear UI control.
            the value is an array of 2 floats, each between 0 and 1, representing horizontal and vertical position of
            a joystick-like control.  

            this is the mutable cell that gets set by other cells
        */

        return new Mutable(config.startingJoystick)
    });
    main.variable().define("joystickRaw", ["mutable joystickRaw"], function (mutableJoystickRaw) {
        /*
            observable pattern for mutable values. 
            to depend on for re-evaluation, depend on this cell. otherwise, can depend on the mutable one
        */
        return mutableJoystickRaw.generator
    });

    main.variable().define("svg", ["d3", "DOM", "wid", "height", "mutable joystickRaw"], function (d3, DOM, wid, height, mutableJoystickRaw) {

        const svg = d3.select(DOM.svg(wid, height));
        svg.style('cursor', 'grab')

        /* 
            d3 zoom is not used here for traditional zoom & pan. we use the pan feature for a grab & drag
            control for shearing the 3d shape of the ridgeline chart. 

            this appears to the user similar to grab & drag rotation of a 3d object in a 3d viewer, so it is intuitive.
            in this case it is not 3d rotation, the ridgeline is not rotated.
            it looks similar to rotation, but it's really shearing the 3d object along the depth dimension.

            this type of ridgeline drag control (shearing the shape rather than rotating) is good for
            data vis because the line charts remain directly facing the viewer, allowing the user
            to compare line charts more easily.

            the intuitive expectation of the user, with this kind of control, 
            is to be able to grab, drag, release, and then be able to repeat that, starting where they left off,
            and with bounds set on the translate extent. d3.zoom's pan feature gives exactly that, 
            so d3.zoom is used to produce the grab & drag 3d shearing control.

            the zoom extent is locked, to prevent actual zooming. 

            the extent, translate extent, and initial transform, set here, give us transforms in "zoomed" that 
            are easy to work with: the transform can be dragged to [0,0] at the top left or [wid,height]
            at the bottom right, and those are the boundaries of the transform. this allows us to convert that transform
            data into a generic value between 0 and 1, for each dimension, which is easier to work with in the code.

            having boundaries for the dragging is important, because we are not rotating the ridgeline, but 
            shearing it, so we must set limits on how much it can be sheared. it seemed important that these boundaries
            be based on the dimensions of the svg, so that the control feels connected to the "viewport" of the svg. 
        */
        const zoom = d3.zoom()
            .on("zoom", zoomed)
            .extent([[0, 0], [wid * 2, height * 2]])
            .scaleExtent([1, 1]) // disable zoom
            .translateExtent([[wid * -1, height * -1], [wid * 2, height * 2]])
            .on('start', () => {
                svg.style('cursor', 'grabbing')
            })
            .on('end', () => {
                svg.style('cursor', 'grab')
            });

        const panOverlay = svg.append("rect")
            .attr("width", wid)
            .attr("height", height)
            .attr('id', 'pan-overlay')
            .attr("fill", "transparent")
            .call(zoom);

        zoom.translateTo(panOverlay, wid - (wid * mutableJoystickRaw.value[0]), height - (height * mutableJoystickRaw.value[1]));

        function zoomed(e) {
            svg.selectAll('#visible-g').remove();
            mutableJoystickRaw.value = [e.transform.x / wid, e.transform.y / height];
        }

        return svg;
    });



    return main;
}
