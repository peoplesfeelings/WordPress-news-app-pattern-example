
# Summary

This repo demonstrates a pattern for writing news app code in a WordPress theme. 

# Purpose

I wanted to start having data journalism news apps on my WordPress blog. The blog is about innovative software development for positive impact, and data journalism and data visualization are prime examples of that. Articles in the blog are about creative software work and it would be nice to be able to create a post in the WordPress site that also is a data journalism news app.

Publishing this repo serves 3 purposes:
- Demonstrate a pattern for adding data journalism news apps to a WordPress site
- Demonstrate a news app using climate data from NCEI's CDO v2 API
- Publish the source of the Local Historical Temperature Shearable Ridgeline

# WP Theme Part pattern

The pattern is that this directory can be placed in a WP custom theme, and multiple news apps can be organized into subdirectories of it. The goal of this project was to work out a somewhat modular pattern so that the news app code is contained and portable to another theme.

# Technical design

SCSS that is specific to this particular news app is included here. The SCSS also depends on SCSS files from a parent directory of this repo, not included here. That is how the news app can use SASS from the WP theme, but also is kept separate.

Some Vue components are used, for the loading spinner and for the weather station selection element. D3.js and Observable runtime are used for the chart. So, the weather station Vue component can set one of the data variables in the Observable notebook. Observable renders the D3 chart to an HTML element.

Another approach would have been that the chart and its controls could have been done all in Vue, or all in Observable, and maybe one of those choices would have been better. Observable notebooks seem primarily designed for ObservableHQ platform usage, so the workflow offline is slower, which makes an argument for putting the D3 chart directly in Vue Components, rather than putting the D3 chart in an Observable instance. But, D3 and Observable are optimized and designed for each-other, so that makes an argument for building the chart with Observable. For the stations component, it seemed like a good case for a Vue component, because then the styling could easily be tied to the theme, and the notebook could be kept more easily portable, such as for porting it to ObservableHQ (an intended subsequent project). The combination of Vue and Observable is not bad for this use case.

The API gets connected to the WP Rest API in the theme's functions.php.

That API endpoint is accessed through AJAX by the page, located in the PHP part template.

The final piece to the tech design is the shortcode, which allows a flexible way to include a PHP template in a regular WordPress post. We create a custom shortcode that takes a parameter specifying a PHP template, such as a specific news app PHP template. The PHP template, in turn, can bring in any other assets it needs.

Once this pattern is established, creating a new news app and including it in a post is a straightforward process. The codebase is maintainable, and the news app code, both in general and for specific news apps, is isolated while also integrated with the theme.

# The ObservableHQ choice

ObservableHQ (ObservableHQ is the web platform, distinct from the Observable runtime JS package) notebooks are easily embeddable, and it would have been a good choice to build this chart on the ObservableHQ platform, rather than building it as part of a WordPress theme. If I had known that ObservableHQ offers the feature of secrets for tokens, I probably would have done that. I plan to port this chart to ObservableHQ soon because that platform is excellent for multiple reasons. It offers a rapid workflow and the great convenience of being able to code from your mobile phone. Doing this chart in ObservableHQ will allow others to easily fork it as a new notebook, for use cases calling for shearable ridgelines, CDO API news apps, or both. One trade-off is that the embedded chart approach means an iframe, which may not always be ideal for a website's intended presentation. 

# Installation

This repo is meant to be more of a code example but here's info on how to get it running.

### Placement

Rename the root directory of this repo to "news-app" and place it in your WordPress theme directory. 

### SCSS

You will need to deal with some SASS variables and mixins referenced in the SCSS files, which come from theme SCSS files, not included here. You could do this by 
1. See the paths imported by the SCSS files from above the root directory and create SCSS files where needed.
2. Try to compile the SCSS with `gulp buildNewsAppStyles` and create any needed variables or mixins shown in the errors until there are no errors and it compiles. It's probably just a few px measurement values and a styling mixin.

gulp file:

```
'use strict';

const gulp = require('gulp'),
    sass = require('gulp-sass')(require('sass')),
    rename = require('gulp-rename');

function buildNewsAppStyles () {
    return gulp.src('./news-app/scss/news-app.scss')
        .pipe(sass().on('error', sass.logError))
        .pipe(rename('news-app.css'))
        .pipe(gulp.dest('./css'));
}
exports.buildNewsAppStyles = buildNewsAppStyles;
```

Alternately, you could just convert the SCSS here to CSS and put the CSS at `[theme]/news-app/css/news-app.css`

### API

Connect the API PHP file to the WP Rest API in your functions.php:
```
// news app api stuff

require(dirname(__FILE__)."/news-app/api/historical_weather.php");
add_action('rest_api_init', function () {
    register_rest_route('historicalWeather', '/getData/zip=(?P<zip>\d+)/distance=(?P<distance>\d+)/duration=(?P<duration>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_data',
        'permission_callback' => '__return_true',
    ));
});
```

### Keys

You will need a file named `config.ini` in the api folder of this repo, with these keys pairs
```
cdo_v2_api_key=myapikeygoeshere
mapquest_key=myapikeygoeshere
```
Get your CDO token here: https://www.ncdc.noaa.gov/cdo-web/token  
Geocoding to convert ZIP to coordinates uses Mapquest API, sign up here: https://developer.mapquest.com/user/login/sign-up  

### Shortcode

Add the shortcode by putting something like this in your theme's functions.php:
```
function PHP_Include($params = array())
{
    $all_attrs_and_defaults = array(
        'file' => ''
    );

    extract(shortcode_atts($all_attrs_and_defaults, $params));

    $file_path = get_theme_root() . '/' . get_template() . "/$file.php";
    ob_start();
    include($file_path);
    return ob_get_clean();
}
add_shortcode('include', 'PHP_Include');
```

Make a post and use the shortcode to bring in the ridgeline PHP:
```
[include file="news-app/parts/ridgeline/ridgeline"]
```






# Resources

CDO v2 API Documentation: https://www.ncdc.noaa.gov/cdo-web/webservices/v2#gettingStarted  
Let me know if you have any issues. peoplesfeelingscode@gmail.com


# License

BSD 2-clause
