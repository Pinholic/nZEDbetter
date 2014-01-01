<?php
if(is_file("config.php")) {
	require_once("config.php");
} else {
	if(is_dir("install")) {
		header("location: install");
		exit();
	} 
}

require_once("config.php");
require_once(WWW_DIR."/lib/page.php");
require_once(WWW_DIR."/lib/users.php");

$page = new Page;
$users = new Users;

switch($page->page) {
	case 'content':
	case 'sendtosab':
	case 'browse':
	case 'browsegroup':
	case 'predb':
	case 'search':
	case 'apihelp':
	case 'rss-info':
	case 'movies':
	case 'movie':
	case 'series':
	case 'anime':
	case 'music':
	case 'books':
	case 'musicmodal':
	case 'consolemodal':
	case 'bookmodal':
	case 'console':
	case 'nfo':
	case 'details':
	case 'forum':
	case 'forumpost':
	case 'filelist':
	case 'getimage':
	case 'cart':
	case 'myshows':
	case 'mymovies':
	case 'mymoviesedit':
	case 'queue':
	case 'queuedata':
	case 'profile':
	case 'profileedit':
	case 'logout':
	case 'register':
	case 'forgottenpassword':
	case 'sitemap':
	case 'contact-us':
	case 'terms-and-conditions':
    case 'ajax_get_book_genres':
	case 'ajax_profile':
	case 'ajax_release-admin':
	case 'ajax_rarfilelist':
	case 'ajax_tvinfo':
	case 'ajax_mediainfo':
	case 'ajax_mymovies':
    case 'ajax-movies':
	case 'calendar':
	case 'upcoming':
		// don't show these pages if it's an API-only site
		if (!$users->isLoggedIn() && $page->site->registerstatus == Sites::REGISTER_STATUS_API_ONLY)
		{
			header("Location: ".$page->site->code);
			break;
		}
	case 'rss':
	case 'api':
	case 'getnzb':
	case 'login':
    case 'login_proc':
		include(WWW_DIR.'pages/'.$page->page.'.php');
    break;

	default:
		$page->show404();
	break;
}
?>
