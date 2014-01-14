<?php
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/amazon.php");
require_once(WWW_DIR."/lib/category.php");
require_once(WWW_DIR."/lib/genres.php");
require_once(WWW_DIR."/lib/site.php");
require_once(WWW_DIR."/lib/util.php");
require_once(WWW_DIR."/lib/releaseimage.php");
require_once(WWW_DIR."/lib/namecleaning.php");
require_once(WWW_DIR . "lib/MusicBrainz.php");

class Music
{
	function Music($echooutput=false)
	{
		$this->echooutput = $echooutput;
		$s = new Sites();
		$site = $s->get();
		$this->pubkey = $site->amazonpubkey;
		$this->privkey = $site->amazonprivkey;
		$this->asstag = $site->amazonassociatetag;
		$this->musicqty = (!empty($site->maxmusicprocessed)) ? $site->maxmusicprocessed : 150;
		$this->sleeptime = (!empty($site->amazonsleep)) ? $site->amazonsleep : 1000;
        $this->getAmazonRating = (!empty($site->getAmazonRating)) ? $site->getAmazonRating : 'FALSE';
        $this->useMusicBrainz = (!empty($site->music_search_MB)) ? $site->music_search_MB : 0;
		$this->imgSavePath = WWW_DIR.'covers/music/';
	}

	public function getMusicInfo($id)
	{
		$db = new DB();
		return $db->queryOneRow(sprintf("SELECT musicinfo.*, genres.title as genres FROM musicinfo left outer join genres on genres.ID = musicinfo.genreID where musicinfo.ID = %d ", $id));
	}

	public function getMusicInfoByName($artist, $album)
	{
		$db = new DB();
		return $db->queryOneRow(sprintf("SELECT * FROM musicinfo where title like %s and artist like %s", $db->escapeString("%".$artist."%"),  $db->escapeString("%".$album."%")));
	}

	public function getRange($start, $num)
	{
		$db = new DB();

		if ($start === false)
			$limit = "";
		else
			$limit = " LIMIT ".$start.",".$num;

		return $db->query(" SELECT * FROM musicinfo ORDER BY createddate DESC".$limit);
	}

	public function getCount()
	{
		$db = new DB();
		$res = $db->queryOneRow("select count(ID) as num from musicinfo");
		return $res["num"];
	}

	public function getMusicCount($cat, $maxage=-1, $excludedcats=array())
	{
		$db = new DB();

		$browseby = $this->getBrowseBy();

		$catsrch = "";
		if (count($cat) > 0 && $cat[0] != -1)
		{
			$catsrch = " (";
			foreach ($cat as $category)
			{
				if ($category != -1)
				{
					$categ = new Category();
					if ($categ->isParent($category))
					{
						$children = $categ->getChildren($category);
						$chlist = "-99";
						foreach ($children as $child)
							$chlist.=", ".$child["ID"];

						if ($chlist != "-99")
								$catsrch .= " r.categoryID in (".$chlist.") or ";
					}
					else
					{
						$catsrch .= sprintf(" r.categoryID = %d or ", $category);
					}
				}
			}
			$catsrch.= "1=2 )";
		}

		if ($maxage > 0)
			$maxage = sprintf(" and r.postdate > now() - interval %d day ", $maxage);
		else
			$maxage = "";

		$exccatlist = "";
		if (count($excludedcats) > 0)
			$exccatlist = " and r.categoryID not in (".implode(",", $excludedcats).")";

		$sql = sprintf("select count(r.ID) as num from releases r inner join musicinfo m on m.ID = r.musicinfoID and m.title != '' where r.passwordstatus <= (select value from site where setting='showpasswordedrelease') and %s %s %s %s", $browseby, $catsrch, $maxage, $exccatlist);
		$res = $db->queryOneRow($sql);
		return $res["num"];
	}

	public function getMusicRange($cat, $start, $num, $orderby, $maxage=-1, $excludedcats=array())
	{
		$db = new DB();

		$browseby = $this->getBrowseBy();

		if ($start === false)
			$limit = "";
		else
			$limit = " LIMIT ".$start.",".$num;

		$catsrch = "";
		if (count($cat) > 0 && $cat[0] != -1)
		{
			$catsrch = " (";
			foreach ($cat as $category)
			{
				if ($category != -1)
				{
					$categ = new Category();
					if ($categ->isParent($category))
					{
						$children = $categ->getChildren($category);
						$chlist = "-99";
						foreach ($children as $child)
							$chlist.=", ".$child["ID"];

						if ($chlist != "-99")
								$catsrch .= " r.categoryID in (".$chlist.") or ";
					}
					else
					{
						$catsrch .= sprintf(" r.categoryID = %d or ", $category);
					}
				}
			}
			$catsrch.= "1=2 )";
		}

		$maxage = "";
		if ($maxage > 0)
			$maxage = sprintf(" and r.postdate > now() - interval %d day ", $maxage);

		$exccatlist = "";
		if (count($excludedcats) > 0)
			$exccatlist = " and r.categoryID not in (".implode(",", $excludedcats).")";

		$order = $this->getMusicOrder($orderby);
		$sql = sprintf(" SELECT r.*, r.ID as releaseID, m.*, g.title as genre, groups.name as group_name, concat(cp.title, ' > ', c.title) as category_name, concat(cp.ID, ',', c.ID) as category_ids, rn.ID as nfoID from releases r left outer join groups on groups.ID = r.groupID inner join musicinfo m on m.ID = r.musicinfoID and m.title != '' left outer join releasenfo rn on rn.releaseID = r.ID and rn.nfo is not null left outer join category c on c.ID = r.categoryID left outer join category cp on cp.ID = c.parentID left outer join genres g on g.ID = m.genreID where r.passwordstatus <= (select value from site where setting='showpasswordedrelease') and %s %s %s %s order by %s %s".$limit, $browseby, $catsrch, $maxage, $exccatlist, $order[0], $order[1]);
		return $db->query($sql);
	}

	public function getMusicOrder($orderby)
	{
		$order = ($orderby == '') ? 'r.postdate' : $orderby;
		$orderArr = explode("_", $order);
		switch($orderArr[0]) {
			case 'artist':
				$orderfield = 'm.artist';
			break;
			case 'size':
				$orderfield = 'r.size';
			break;
			case 'files':
				$orderfield = 'r.totalpart';
			break;
			case 'stats':
				$orderfield = 'r.grabs';
			break;
			case 'year':
				$orderfield = 'm.year';
			break;
			case 'genre':
				$orderfield = 'm.genreID';
			break;
			case 'posted':
			default:
				$orderfield = 'r.postdate';
			break;
		}
		$ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';
		return array($orderfield, $ordersort);
	}

	public function getMusicOrdering()
	{
		return array('artist_asc', 'artist_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc', 'files_asc', 'files_desc', 'stats_asc', 'stats_desc', 'year_asc', 'year_desc', 'genre_asc', 'genre_desc');
	}

	public function getBrowseByOptions()
	{
		return array('artist'=>'artist', 'title'=>'title', 'genre'=>'genreID', 'year'=>'year');
	}

	public function getBrowseBy()
	{
		$db = new Db;

		$browseby = ' ';
		$browsebyArr = $this->getBrowseByOptions();
		foreach ($browsebyArr as $bbk=>$bbv) {
			if (isset($_REQUEST[$bbk]) && !empty($_REQUEST[$bbk])) {
				$bbs = stripslashes($_REQUEST[$bbk]);
				if (preg_match('/id/i', $bbv)) {
					$browseby .= "m.{$bbv} = $bbs AND ";
				} else {
					$browseby .= "m.$bbv LIKE(".$db->escapeString('%'.$bbs.'%').") AND ";
				}
			}
		}
		return $browseby;
	}

	public function makeFieldLinks($data, $field)
	{
		$tmpArr = explode(', ',$data[$field]);
		$newArr = array();
		$i = 0;
		foreach($tmpArr as $ta) {
			if ($i > 5) { break; } //only use first 6
			$newArr[] = '<a href="'.WWW_TOP.'/music?'.$field.'='.urlencode($ta).'" title="'.$ta.'">'.$ta.'</a>';
			$i++;
		}
		return implode(', ', $newArr);
	}

	public function update($id, $title, $asin, $url, $salesrank, $artist, $publisher, $releasedate, $year, $tracks, $cover, $genreID)
	{
		$db = new DB();

		$db->query(sprintf("UPDATE musicinfo SET title=%s, asin=%s, url=%s, salesrank=%s, artist=%s, publisher=%s, releasedate='%s', year=%s, tracks=%s, cover=%d, genreID=%d, updateddate=NOW() WHERE ID = %d",
		$db->escapeString($title), $db->escapeString($asin), $db->escapeString($url), $salesrank, $db->escapeString($artist), $db->escapeString($publisher), $releasedate, $db->escapeString($year), $db->escapeString($tracks), $cover, $genreID, $id));
	}

	public function updateMusicInfo($title, $year, $amazdata = null)
	{
		$db = new DB();
		$gen = new Genres();
		$ri = new ReleaseImage();

		$mus = array();
		if ($title != '')
			$amaz = $this->fetchAmazonProperties($title);
		elseif ($amazdata != null)
			$amaz = $amazdata;
		if (!$amaz) {
			// echo "Failed at Amazon Match\n";
			return false;
		}
		// Load genres.
		$defaultGenres = $gen->getGenres(Genres::MUSIC_TYPE);
		$genreassoc = array();
		foreach($defaultGenres as $dg){
			$genreassoc[$dg['ID']] = strtolower($dg['title']);
		}

		//
		// Get album properties.
		//

		$mus['coverurl'] = (string) $amaz->Items->Item->LargeImage->URL;
		if ($mus['coverurl'] != "")
			$mus['cover'] = 1;
		else
			$mus['cover'] = 0;

		$mus['title'] = (string) $amaz->Items->Item->ItemAttributes->Title;
		if (empty($mus['title'])) {
			// echo "Failed due to blank title";
			return false;
		}
		$mus['asin'] = (string) $amaz->Items->Item->ASIN;

		$mus['url'] = (string) $amaz->Items->Item->DetailPageURL;
		$mus['url'] = str_replace("%26tag%3Dws", "%26tag%3Dopensourceins%2D21", $mus['url']);

		$mus['salesrank'] = (string) $amaz->Items->Item->SalesRank;
		if ($mus['salesrank'] == "")
			$mus['salesrank'] = 'null';

		$mus['artist'] = (string) $amaz->Items->Item->ItemAttributes->Artist;
		if (empty($mus['artist'])){
			// Amazon doesn't return an Artist field when it's an MP3 Download	
			$mus['artist'] = (string) $amaz->Items->Item->ItemAttributes->Creator;
			if (empty($mus['artist']))
				$mus['artist'] = "";
		}
		$mus['publisher'] = (string) $amaz->Items->Item->ItemAttributes->Publisher;

		$mus['releasedate'] = $db->escapeString((string) $amaz->Items->Item->ItemAttributes->ReleaseDate);
		if ($mus['releasedate'] == "''")
			$mus['releasedate'] = 'null';

		$mus['review'] = "";
		if (isset($amaz->Items->Item->EditorialReviews))
			$mus['review'] = trim(strip_tags((string) $amaz->Items->Item->EditorialReviews->EditorialReview->Content));

		$mus['year'] = $year;
		if ($mus['year'] == "")
			$mus['year'] = ($mus['releasedate'] != 'null' ? substr($mus['releasedate'], 1, 4) : date("Y"));

		$mus['tracks'] = "";
		if (isset($amaz->Items->Item->Tracks))
		{
			$tmpTracks = (array) $amaz->Items->Item->Tracks->Disc;
			$tracks = $tmpTracks['Track'];
			$mus['tracks'] = (is_array($tracks) && !empty($tracks)) ? implode('|', $tracks) : '';
		}

		similar_text($mus['artist']." - ".$mus['title'], $title, $titlepercent);
		if ($titlepercent < 60)
		{
			echo "Failed because titles didn't match: ".$titlepercent."\n";
			echo "Ours: ".$title."\n";
			echo "Amaz: ".$mus['artist']." - ".$mus['title']."\n";
			return false;
		}

        if($this->getAmazonRating == 'TRUE')
        {
            if(isset($amaz->Items->Item->CustomerReviews->HasReviews) && $amaz->Items->Item->CustomerReviews->HasReviews == 'true')
            {
                $obj = new AmazonProductAPI($this->pubkey, $this->privkey, $this->asstag);
                $mus['customerRating'] = $obj->getAmazonCustomerRating($amaz->Items->Item->CustomerReviews->IFrameURL);
            }
            else
                $mus['customerRating'] = 'null';
        }
        else
            $mus['customerRating'] = 'null';

		$genreKey = -1;
		$genreName = '';
		if (isset($amaz->Items->Item->BrowseNodes))
		{
			// Had issues getting this out of the browsenodes obj.
			// Workaround is to get the xml and load that into its own obj.
			$amazGenresXml = $amaz->Items->Item->BrowseNodes->asXml();
			$amazGenresObj = simplexml_load_string($amazGenresXml);
			$amazGenres = $amazGenresObj->xpath("//BrowseNodeId");

			foreach($amazGenres as $amazGenre)
			{
				$currNode = trim($amazGenre[0]);
				if (empty($genreName))
				{
					$genreMatch = $this->matchBrowseNode($currNode);
					if ($genreMatch !== false)
					{
						$genreName = $genreMatch;
						break;
					}
				}
			}

			if (in_array(strtolower($genreName), $genreassoc)) {
				$genreKey = array_search(strtolower($genreName), $genreassoc);
			} else {
				$genreKey = $db->queryInsert(sprintf("INSERT IGNORE INTO genres (`title`, `type`) VALUES (%s, %d)", $db->escapeString($genreName), Genres::MUSIC_TYPE));
			}
		}
		$mus['musicgenre'] = $genreName;
		$mus['musicgenreID'] = $genreKey;

		$query = sprintf("
		INSERT IGNORE INTO musicinfo  (`title`, `asin`, `url`, `salesrank`,  `artist`, `publisher`, `releasedate`, `review`, `year`, `genreID`, `tracks`, `cover`, `createddate`, `updateddate`, `customerRating`)
		VALUES (%s,		%s,		%s,		%s,		%s,		%s,		%s,		%s,		%s,		%s,		%s,		%d,		now(),		now(), %s )
			ON DUPLICATE KEY UPDATE  `title` = %s,  `asin` = %s,  `url` = %s,  `salesrank` = %s,  `artist` = %s,  `publisher` = %s,  `releasedate` = %s,  `review` = %s,  `year` = %s,  `genreID` = %s,  `tracks` = %s,  `cover` = %d,  createddate = now(),  updateddate = now(), `customerRating` = %s",
		$db->escapeString($mus['title']), $db->escapeString($mus['asin']), $db->escapeString($mus['url']),
		$mus['salesrank'], $db->escapeString($mus['artist']), $db->escapeString($mus['publisher']),
		$mus['releasedate'], $db->escapeString($mus['review']), $db->escapeString($mus['year']),
		($mus['musicgenreID']==-1?"null":$mus['musicgenreID']), $db->escapeString($mus['tracks']), $mus['cover'], $mus['customerRating'],
		$db->escapeString($mus['title']), $db->escapeString($mus['asin']), $db->escapeString($mus['url']),
		$mus['salesrank'], $db->escapeString($mus['artist']), $db->escapeString($mus['publisher']),
		$mus['releasedate'], $db->escapeString($mus['review']), $db->escapeString($mus['year']),
		($mus['musicgenreID']==-1?"null":$mus['musicgenreID']), $db->escapeString($mus['tracks']), $mus['cover'], $mus['customerRating'] );

		$musicId = $db->queryInsert($query);

		if ($musicId)
		{
			if ($this->echooutput)
			{
				if ($mus["artist"] == "")
					$artist = "";
				else
					$artist = "Artist: ".$mus['artist'].", Album: ";
				echo "added/updated album: ".$artist.$mus['title']." (".$mus['year'].")\n";
			}

			$mus['cover'] = $ri->saveImage($musicId, $mus['coverurl'], $this->imgSavePath, 250, 250);
		}
		else
		{
			if ($this->echooutput)
			{
				if ($mus["artist"] == "")
					$artist = "";
				else
					$artist = "Artist: ".$mus['artist'].", Album: ";
				echo "nothing to update: ".$artist.$mus['title']." (".$mus['year'].")\n";
			}
		}

		return $musicId;
	}

	public function fetchAmazonProperties($title)
	{
		$obj = new AmazonProductAPI($this->pubkey, $this->privkey, $this->asstag);
		try
		{
			$result = $obj->searchProducts($title, AmazonProductAPI::MUSIC, "TITLE");
		}
		catch(Exception $e)
		{
			//if first search failed try the mp3downloads section
			try
			{
				$result = $obj->searchProducts($title, AmazonProductAPI::MP3, "TITLE");
			}
			catch(Exception $e2)
			{
				$result = false;
			}
		}
		return $result;
	}

	public function processMusicReleases($threads=1)
	{
		$threads--;
		$db = new DB();
		$res = $db->queryDirect(sprintf("SELECT searchname, ID, name from releases where musicinfoID IS NULL and nzbstatus = 1 and relnamestatus != 0 and categoryID in (3010, 3040, 3050, 3070) ORDER BY postdate desc LIMIT %d,%d",
                floor(max(0, $this->musicqty * $threads * 1.5)), $this->musicqty));
		if ($db->getNumRows($res) > 0)
		{
			if ($this->echooutput)
				echo "Processing ".$db->getNumRows($res)." music release(s).\n";

			if($this->useMusicBrainz == 1)
                $musicBrainz = new MusicBrainz();

            while ($arr = $db->fetchAssoc($res))
			{
				if($this->useMusicBrainz == 0)
                {
                    $album = $this->parseArtist($arr['searchname']);
                    if ($album !== false)
                    {
                        $newname = $album["name"].' ('.$album["year"].')';
                        preg_replace('/ \( /', ' ', $newname);
                        if ($this->echooutput)
                            echo 'Looking up: '.$newname."\n";

                        $albumId = $this->updateMusicInfo($album["name"], $album['year']);
                        if ($albumId === false)
                        {
                            $albumId = -2;
                            $logfile = WWW_DIR."lib/logging/musicfailed.log";
                            file_put_contents($logfile, $arr['ID']." ".$newname."\n", FILE_APPEND);
                        }

                        // Update release.
                        $db->query(sprintf("UPDATE releases SET musicinfoID = %d WHERE ID = %d", $albumId, $arr["ID"]));
                        usleep($this->sleeptime*1000);
                    }
                    else
                    {
                        // No year was found in the name.  Suspect this may be a single for now.
                        $db->query(sprintf("UPDATE releases SET musicinfoID = %d, categoryID=3070 WHERE ID = %d", -2, $arr["ID"]));
                        echo "Added ".$arr['searchname']." to Singles category.\n";

                    }

                }
                else
                {
                    $mbResult = $musicBrainz->processMusicRelease($arr);
                    if($mbResult === false)
                        echo "\033[01;31mUnable to match release: " . $arr['searchname'] . "\n\033[01;37m";
                }
			}
		}
	}

	public function parseArtist($releasename)
	{
		if (preg_match('/(.+?)(\d{1,2} \d{1,2} )?(19\d{2}|20[0-1][0-9])/', $releasename, $name))
		{
			$namecleaning = new nameCleaning();
            $result = array();
			$result["year"] = $name[3];

            $newname = $namecleaning->musicCleaner($name[1]);
			if (!preg_match('/^[a-z0-9]+$/i', $newname) && strlen($newname) > 10)
			{
				$result["name"] = $newname;
				return $result;
			}
			else
				return false;
		}
		else
			return false;
	}

	public function getGenres($activeOnly=false)
	{
		$db = new DB();
		if ($activeOnly)
			return $db->query("SELECT musicgenre.* FROM musicgenre INNER JOIN (SELECT DISTINCT musicgenreID FROM musicinfo) X ON X.musicgenreID = musicgenre.ID ORDER BY title");
		else
			return $db->query("select * from musicgenre order by title");
	}

	public function matchBrowseNode($nodeId)
	{
		$str = '';

		//music nodes above mp3 download nodes
		switch($nodeId)
		{
			case '163420':
				$str = 'Music Video & Concerts';
				break;
			case '30':
			case '624869011':
				$str = 'Alternative Rock';
				break;
			case '31':
			case '624881011':
				$str = 'Blues';
				break;
			case '265640':
			case '624894011':
				$str = 'Broadway & Vocalists';
				break;
			case '173425':
			case '624899011':
				$str = "Children's Music";
				break;
			case '173429': //christian
			case '2231705011': //gospel
			case '624905011': //christian & gospel
				$str = 'Christian & Gospel';
				break;
			case '67204':
			case '624916011':
				$str = 'Classic Rock';
				break;
			case '85':
			case '624926011':
				$str = 'Classical';
				break;
			case '16':
			case '624976011':
				$str = 'Country';
				break;
			case '7': //dance & electronic
			case '624988011': //dance & dj
				$str = 'Dance & Electronic';
				break;
			case '32':
			case '625003011':
				$str = 'Folk';
				break;
			case '67207':
			case '625011011':
				$str = 'Hard Rock & Metal';
				break;
			case '33': //world music
			case '625021011': //international
				$str = 'World Music';
				break;
			case '34':
			case '625036011':
				$str = 'Jazz';
				break;
			case '289122':
			case '625054011':
				$str = 'Latin Music';
				break;
			case '36':
			case '625070011':
				$str = 'New Age';
				break;
			case '625075011':
				$str = 'Opera & Vocal';
				break;
			case '37':
			case '625092011':
				$str = 'Pop';
				break;
			case '39':
			case '625105011':
				$str = 'R&B';
				break;
			case '38':
			case '625117011':
				$str = 'Rap & Hip-Hop';
				break;
			case '40':
			case '625129011':
				$str = 'Rock';
				break;
			case '42':
			case '625144011':
				$str = 'Soundtracks';
				break;
			case '35':
			case '625061011':
				$str = 'Miscellaneous';
				break;
		}
		return ($str != '') ? $str : false;
	}

}
