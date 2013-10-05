<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/html">
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7"> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8"> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js"> <!--<![endif]-->
<head>
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="keywords" content="{$page->meta_keywords}{if $site->metakeywords != ""},{$site->metakeywords}{/if}">
    <meta name="description" content="{$page->meta_description}{if $site->metadescription != ""} - {$site->metadescription}{/if}">
    <meta name="application-name" content="nZEDb-v{$site->version}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$page->meta_title}{if $site->metatitle != ""} - {$site->metatitle}{/if}</title>
    {if $loggedin=="true"}<link rel="alternate" type="application/rss+xml" title="{$site->title} Full Rss Feed" href="{$smarty.const.WWW_TOP}/rss?t=0&amp;dl=1&amp;i={$userdata.ID}&amp;r={$userdata.rsstoken}">{/if}

    <!-- nZEDb core CSS -->
    {if $site->useMinify == '0' || $site->useMinify == ''}
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/bootstrap.css" rel="stylesheet" media="screen">
        {* <link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css" rel="stylesheet"> *}
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/font-awesome/css/font-awesome.css" rel="stylesheet">
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/style.css" rel="stylesheet" media="screen">
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/wip.css" rel="stylesheet" media="screen">
        <!-- nZEDb extras -->
        {if $site->google_adsense_acc != ''}<link href="http://www.google.com/cse/api/branding.css" rel="stylesheet" media="screen">{/if}
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/jquery.pnotify.default.css" rel="stylesheet" media="screen">
        <link href="{$smarty.const.WWW_TOP}/themes/{$site->style}/styles/jquery.qtip.css" rel="stylesheet" media="screen">
    {/if}

    {if $site->useMinify == '1'}
        <link type="text/css" rel="stylesheet" href="/min/b=themes/{$site->style}/styles&amp;f=bootstrap.css,style.css,wip.css,jquery.pnotify.default.css,jquery.qtip.css" />
        <link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css" rel="stylesheet">
        {if $site->google_adsense_acc != ''}<link href="http://www.google.com/cse/api/branding.css" rel="stylesheet" media="screen">{/if}
    {/if}
    <!-- Manual Adjustment for Search input fields on browse pages. -->
    <style>
        .panel .list-group { margin-top: -1px; }
        fieldset.adbanner {
            border: 1px groove #ddd !important;
            padding: 4px 15px 15px;
        }
        legend.adbanner {
            font-size: 11px !important;
            font-weight: bold !important;
            text-align: left !important;
            width:auto;
            padding: 0 2px;
            margin: 0 15px;
            border: 1px groove #ddd !important;
        }
        .dropdown-menu { border: 0; }
        .grey-box .row { margin-left:0;margin-right:0; }
        .div-center { float:none;margin-left:auto;margin-right:auto; }
        .rarfilelist img { display:inline;opacity:1;position:relative; }
    </style>

    <!-- Favicons WWWIIIPPP Larger Icons-->
    {*<link rel="apple-touch-icon-precomposed" sizes="144x144" href="../assets/ico/apple-touch-icon-144-precomposed.png">
    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="../assets/ico/apple-touch-icon-114-precomposed.png">
    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="../assets/ico/apple-touch-icon-72-precomposed.png">
    <link rel="apple-touch-icon-precomposed" href="../assets/ico/apple-touch-icon-57-precomposed.png">*}
    <link rel="shortcut icon" href="{$smarty.const.WWW_TOP}/themes/{$site->style}/images/favicon.ico">

    <!-- Additional nZEDb -->
    <!--[if lt IE 9]>
    <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/html5shiv.js"></script>
    <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/respond.min.js"></script>
    <![endif]-->
    {literal}
    <script>
        /* <![CDATA[ */
        var WWW_TOP = "{/literal}{$smarty.const.WWW_TOP}{literal}";
        var SERVERROOT = "{/literal}{$serverroot}{literal}";
        var UID = "{/literal}{if $loggedin=="true"}{$userdata.ID}{else}{/if}{literal}";
        var RSSTOKEN = "{/literal}{if $loggedin=="true"}{$userdata.rsstoken}{else}{/if}{literal}";
        /* ]]> */
    </script>
    {/literal}
    <!-- JS and analytics only. -->
    <!-- Bootstrap core JavaScript
    ================================================== -->
    {if $site->useMinify == '0' || $site->useMinify == ''}
        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/bootstrap.min.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/holder.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/jquery.pnotify.min.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/jquery.qtip.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/jquery.autosize-min.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/jquery.colorbox-min.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/sorttable.js"></script>
        <script src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/utils.js"></script>
        <script type="text/javascript" src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/jquery.autocomplete.js"></script>
    {/if}

    {if $site->useMinify == '1'}
        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
        <script type="text/javascript" src="/min/b=themes/{$site->style}/scripts&amp;f=bootstrap.min.js,holder.js,jquery.pnotify.min.js,jquery.qtip.js,jquery.autosize-min.js,jquery.colorbox-min.js,sorttable.js,utils.js,jquery.autocomplete.js"></script>
    {/if}
    {$page->head}

</head>
<body {$page->body}>
<!-- Status and Top Menu Area
================================================== -->
<div class="row">
    <div class="container" style="height: 30px">
        {if $site->menuposition == 2}<div class="pull-left">{$main_menu}</div><!-- SITE TOP MENU -->{/if}

        <div class="pull-right">

            {if $loggedin=="true"}
                Welcome back&nbsp;
                <a class="user-menu-options" title="View/Edit Profile" href="{$smarty.const.WWW_TOP}/profile">{$username}</a> | <a class="user-menu-options" href="{$smarty.const.WWW_TOP}/logout">Logout</a><!-- SITE LOGGED IN STATUS -->
            {else}
                <a class="user-menu-options" id="menu-login" {* href="{$smarty.const.WWW_TOP}/login" *} href="#">Login</a> or <a class="user-menu-options" href="{$smarty.const.WWW_TOP}/register">Register</a><!-- SITE LOGGED OUT STATUS -->
            {/if}
        </div><!--/.pull-right -->
    </div><!--/.container -->
</div><!-- end -->
{if $loggedin!="true"}
<div class="loginbox" id="login-box">
    <form id="login-form">
        {* <label for="username">User name:</label><br /> *}
        <input type="text" name="username" class="large" id="username" placeholder="User Name"><br />
        {* <label for="password">Password:</label><br /> *}
        <input type="password" name="password" class="large" id="password" placeholder="Password"><br />
        <input type="checkbox" id="rememberme" name="rememberme"><label for="rememberme">Remember Me</label>
        <button class="btn btn-success btn-small" style="float: right; margin-top: 10px;" id="login-button">Login</button>
    </form>
    <div class="login-failed" id="login-failed"><i class="icon-warning-sign"></i> Login failed.  Please try again.</div>
</div>
{/if}
<!-- Header area containing top menu, status menu, logo, ad header
================================================== -->
<div id="header-wrapper" class="row">
    <div class="container" style="min-height: 65px;">
        <div class="row">
            <div class="col-7 col-sm-7 col-lg-7">
                <div class="media">
                    <a class="pull-left logo" style="padding: 2px 10px;" title="{$site->title}" href="{$smarty.const.WWW_TOP}{$site->home_link}">
                        <img class="media-object" alt="{$site->title} Logo" src="{$smarty.const.WWW_TOP}/themes/{$site->style}/images/clearlogo.png"><!-- SITE LOGO -->
                    </a>
                    <div class="media-body" style="margin:0">
                        <h1 class="media-heading" style="margin:0"><a title="{$site->title}" href="{$smarty.const.WWW_TOP}{$site->home_link}"> {$site->title} </a></h1><!-- SITE TITLE -->
                        <div class="media" style="margin:0"><h4 style="margin:0">{$site->strapline|default:''}</h4></div><!-- SITE STRAPLINE -->
                    </div>
                </div>
            </div><!--/.col-lg- -->
            <div class="col-4 col-sm-4 col-lg-4">
                {$site->adheader}<!-- SITE AD BANNER -->
            </div><!--/.col-lg- -->
        </div><!--/.row -->
    </div><!-- end header-wrapper -->
</div>


<!-- Navigation Menu containing HeaderMenu and HeaderSearch
================================================== -->
<div class="navbar navbar-inverse navbar-static-top">
    <div class="container">
        {if $loggedin=="true"}{$header_menu}{/if}<!-- SITE NAVIGATION -->
    </div><!--/.navbar -->
</div><!-- end Navigation -->


<!-- Content Area containing Side Menu and Main Content Panel
================================================== -->
<div class="row">
    <div class="container">
        {if $site->menuposition == 1 or $site->menuposition == 0}<!-- Side Menu Framework -->
        <div class="col-2 col-sm-2 col-lg-2{if $site->menuposition == 0} col-lg-push-10{/if}">
            {$main_menu}<!-- SIDE MENU -->
            {$article_menu}<!-- SIDE ARTICLES -->
            {$useful_menu}<!-- SIDE USEFUL -->
        </div><!--/.col-2 -->
        {/if}
        <!--Start Main Content - Tables, Detailed Views-->
        <div class="{if $site->menuposition == 1 or $site->menuposition == 0}col-10 col-sm-10 col-lg-10{else}col-12 col-sm-12 col-lg-12{/if}">
            <div class="panel">
                <div class="panel-heading">
                    <h3 class="panel-title">{$page->meta_title|regex_replace:'/Nzbs/i':$catname|escape:"htmlall"}</h3>
                </div><!--/.panel-heading -->
                <div class="grey-frame">
                    <div class="grey-box">

                        <!--[if lt IE 7]>
                        <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
                        <![endif]-->

                        {$page->content}

                    </div><!--/.grey-box -->
                </div><!--/.grey-frame -->
            </div><!--/.panel- -->
        </div><!--/.col-10 -->
    </div><!--/.container -->
</div><!--/.row -->


<!-- Footer Area containing Footer contents
================================================== -->
<footer>
    <p><i class="icon-certificate icon-2x" style="color:yellow;"></i>  <i class="icon-quote-left qoute"></i> {$site->footer} <i class="icon-quote-right qoute"></i></p>
    <p>Copyright &copy; <a href="{$smarty.const.WWW_TOP}{$site->home_link}">{$site->title}</a> all rights reserved {$smarty.now|date_format:"%Y"}</p>
    <ul><li><a href="{$smarty.const.WWW_TOP}{$site->home_link}">Home</a></li>
        <li class="muted"> | </li>
        <li><a href="{$smarty.const.WWW_TOP}/contact-us">Contact Us</a></li>
        <li class="muted"> | </li>
        <li><a href="{$smarty.const.WWW_TOP}/sitemap">Site Map</a></li>
        <li class="muted"> | </li>
        <li><a href="{$smarty.const.WWW_TOP}/apihelp">API</a></li>
        <li class="muted"> | </li>
        <li><a href="{$smarty.const.WWW_TOP}/login">Login</a></li>
    </ul>
    <div style="text-align: center; padding: 10px; display: inline; font-size: larger;">
    <table class="center" style="background: transparent">
        <thead class="panel-title">
            {$site->title} draws data from the following service providers.<br />A sincere thanks to each of them.
        </thead>
        <tr style="vertical-align: middle; text-align: center;">
            <td style="padding: 10px; vertical-align:  middle; text-align: center;"><a href="http://themoviedb.org" target="_blank"><img src="/themes/{$site->style}/images/tmdb.png" /></a></td>
            <td style="padding: 10px; vertical-align: middle; text-align: center;"><a href="http://www.tvrage.com/" target="_blank"><img src="/themes/{$site->style}/images/tvrage.png" /></a></td>
            <td style="padding: 10px; vertical-align: middle; text-align: center;"><a href="http://trakt.tv/" target="_blank"><img src="/themes/{$site->style}/images/traktv.png" /></a></td>
            <td style="padding: 10px; vertical-align: middle; text-align: center;"><a href="http://musicbrainz.org/" target="_blank"><img src="/themes/{$site->style}/images/Musicbrainz_logo.png" /></a></td>
        </tr> 
    </table>
    </div>
</footer>


<!-- Additional nZEDb JS -->

{if $parentCat == 'books'}
    <script type="text/javascript" src="{$smarty.const.WWW_TOP}/themes/{$site->style}/scripts/auto-{$parentCat}.js"></script>
{/if}

<script> Holder.add_theme("dark", { background: "black", foreground: "gray", size: 16 } )</script>
<script>
    jQuery(function(){
        jQuery('.nzb_check, .nzb_check_all').click(function(){
            btb();
        });

        var btb = function() {
            var count = jQuery('.nzb_check:checked').size();
            if(count == 0) {
                jQuery('.nzb_multi_operations .btn-info').removeClass('btn-info').addClass('btn-default').addClass('disabled');
                jQuery('.nzb_multi_operations .btn-success').addClass('disabled');
                jQuery('.nzb_multi_operations .btn-warning').addClass('disabled');
                jQuery('.nzb_multi_operations .btn-danger').addClass('disabled');
            } else {
                jQuery('.nzb_multi_operations .btn-success').removeClass('disabled');
                jQuery('.nzb_multi_operations .btn-warning').removeClass('disabled');
                jQuery('.nzb_multi_operations .btn-danger').removeClass('disabled');
                jQuery('.nzb_multi_operations .btn-default').removeClass('btn-default').addClass('btn-info').removeClass('disabled');
            }
        }
        btb();
    });
</script>

{if $site->google_analytics_acc != ''}
    <!-- Analytics
    ================================================== -->
{literal}<script>
    /* <![CDATA[ */
    var _gaq = _gaq || [];
    _gaq.push(['_setAccount', '{/literal}{$site->google_analytics_acc}{literal}']);
    _gaq.push(['_trackPageview']);
    _gaq.push(['_trackPageLoadTime']);

    (function() {
        var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
        ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
        var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
    })();
    /* ]]> */
</script>{/literal}
{/if}

{if $loggedin=="true"}
    <input type="hidden" name="UID" value="{$userdata.ID}">
    <input type="hidden" name="RSSTOKEN" value="{$userdata.rsstoken}">
{/if}

</body>
</html>
