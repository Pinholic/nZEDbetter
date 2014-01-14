<?php
/**
 * Project: nZEDbetter
 * User: Randy
 * Date: 9/7/13
 * Time: 2:00 PM
 * File: MusicBrainz.php
 *
 * Class for retrieving music info from a MusicBrainz replication server.  To configure your own
 * replication server, see http://nzedbetter.org/index.php?title=MusicBrainz
 *
 * It is STRONGLY recommended that you configure your own replication server if
 * you plan to index music binaries and utilize the MusicBrainz integration.
 *
 * NOTE: All http requests to musicbrainz are in compliance with the MusicBrainz
 * terms of service, provided that the code below has not been altered from the
 * author's original work.  For the current release version of nZEDbetter, please visit
 * https://github.com/KurzonDax/nZEDbetter
 *
 */

require_once(WWW_DIR . "lib/site.php");
require_once(WWW_DIR . "lib/framework/db.php");
require_once(WWW_DIR . "lib/releaseimage.php");
require_once(WWW_DIR . "lib/amazon.php");
require_once(WWW_DIR . "lib/MusicBrainz/mb_base.php");
require_once(WWW_DIR . "lib/MusicBrainz/mbArtist.php");
require_once(WWW_DIR . "lib/MusicBrainz/mbRelease.php");
require_once(WWW_DIR . "lib/MusicBrainz/mbTrack.php");
require_once(WWW_DIR . "lib/namecleaning.php");

/**
 * Class MusicBrainz
 */
class MusicBrainz {

    const POST = 'post';
    const GET = 'get';
    const HEAD = 'head';
    const API_VERSION = '2';
    const API_SCHEME = "http://";
    const DEBUG_MODE = false;
    const COVER_ART_BASE_URL = "http://coverartarchive.org/release/";
    const WRITE_LOG_FILES = true;

    /**
     * @var string
     */
    private $_MBserver = '';
    /**
     * @var bool
     */
    private $_throttleRequests = false;
    /**
     * @var string
     */
    private $_applicationName = 'nZEDbetter';
    /**
     * @var string
     */
    private $_applicationVersion = '';
    /**
     * @var null
     */
    private $_email = null;
    /**
     * @var string
     */
    private $_imageSavePath = '';
    /**
     * @var bool
     */
    private $_isAmazonValid = false;
    /**
     * @var string
     */
    private $_amazonPublicKey = '';
    /**
     * @var string
     */
    private $_amazonPrivateKey = '';
    /**
     * @var string
     */
    private $_amazonTag = '';
    /**
     * @var string
     */
    private $_baseLogPath = '';
    /**
     * @var int
     */
    private $_threads = 0;

    /**
     * @throws MBException      exception thrown if search server URL contains musicbrainz.org
     *                          and no valid email address has been configured in site settings.
     *
     * NOTE: All requests to musicbrainz are in compliance with the MusicBrainz terms
     * of service, provided that the code below has not been altered from the author's
     * original work.  For the current release version of nZEDbetter, please visit
     * https://github.com/KurzonDax/nZEDbetter
     */
    function construct()
    {
        $s = new Sites();
        $site = $s->get();
        $this->_MBserver = (!empty($site->musicBrainzServer)) ? $site->musicBrainzServer : "musicbrainz.org";
        $this->_email = !empty($site->email) ? $site->email : null;
        $this->_applicationVersion = $site->NZEDBETTER_VERSION;
        $this->_imageSavePath = WWW_DIR . "covers/music/";
        $this->_amazonPrivateKey = !empty($site->amazonprivkey) ? $site->amazonprivkey : '';
        $this->_amazonPublicKey = !empty($site->amazonpubkey) ? $site->amazonpubkey : '';
        $this->_amazonTag = !empty($site->amazonassociatetag) ? $site->amazonassociatetag : '';
        $this->_threads = !empty($site->postthreadsamazon) ? $site->postthreadsamazon : 1;

        if($this->_amazonPrivateKey != '' && $this->_amazonPublicKey != '' && $this->_amazonTag != '')
            $this->_isAmazonValid = true;

        if(stripos($this->_MBserver, 'musicbrainz.org') === false)
        {
            $this->_throttleRequests = false;
        }
        else
        {
            $this->_throttleRequests = true;
            if(is_null($this->_email) || empty($this->_email) ||
            preg_match('/[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:[A-Z]{2}|com|org|net|gov|mil|biz|info|mobi|name|aero|jobs|museum)\b/i', $this->_email) === 0)
            {
                echo "\n\033[01;31mALERT!!! You have not set a valid email address in Admin->Site Settings.\n";
                echo "The MusicBrainz integration will not function until this is corrected.\n\n";
                throw new MBException('Invalid email address. Halting MusicBrainz Integration.');
            }
        }

        if(MusicBrainz::WRITE_LOG_FILES)
        {
            $this->_baseLogPath = WWW_DIR . "lib/logging/musicBrainz/";
            mkdir($this->_baseLogPath, 0777, true);
        }

    }

    /**
     * @param string $searchFunction
     * @param string $field
     * @param string $query
     * @param int    $limit
     *
     * @return bool|SimpleXMLElement
     */
    private function __makeSearchCall($searchFunction, $field = '' , $query = '', $limit=10)
    {

        $url = MusicBrainz::API_SCHEME . $this->_MBserver . '/ws/' . MusicBrainz::API_VERSION . '/' . $searchFunction . '?query=' . ($field=='' ? '' : $field . '%3A') . rawurlencode($query) . "&limit=" . $limit;

        return $this->__getResponse($url);

    }

    /**
     * @param string    $entity   string literal: artist, label, recording, release, release-group, work, area, url
     * @param string    $mbid
     *
     * @return bool|SimpleXMLElement
     */
    public function musicBrainzLookup($entity, $mbid)
    {
        // $entity must be one of the following:
        // artist, label, recording, release, release-group, work, area, url
        if(is_null($entity) || empty($entity) || is_null($mbid) || empty($mbid))
            return false;

        $validEntities = array('artist', 'label', 'recording', 'release', 'release-group', 'work', 'area', 'url');

        if(!in_array($entity, $validEntities))
            return false;

        switch ($entity)
        {
            case 'artist':
                $incParams = '?inc=ratings+tags';
                break;
            case 'release':
                $incParams = '?inc=ratings+tags+release-groups+media+release-rels';
                break;
            case 'recording':
                $incParams = '?inc=ratings+tags+artists+releases';
                break;
            default:
                $incParams = '';
        }

        $url = MusicBrainz::API_SCHEME . $this->_MBserver . '/ws/' . MusicBrainz::API_VERSION . '/' . $entity . '/' . $mbid . $incParams;

        return $this->__getResponse($url);

    }

    /**
     * @param string $query
     * @param string $field     defaults to blank
     * @param int    $limit     defaults to 10, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchArtist($query, $field='', $limit=10)
    {
        /*  FIELD       DESCRIPTION
         *  area		artist area
            beginarea	artist begin area
            endarea		artist end area
            arid		MBID of the artist
            artist		name of the artist
            artistaccent	 name of the artist with any accent characters retained
            alias		the aliases/misspellings for the artist
            begin		artist birth date/band founding date
            comment		artist comment to differentiate similar artists
            country		the two letter country code for the artist country or 'unknown'
            end			artist death date/band dissolution date
            ended		true if know ended even if do not know end date
            gender		gender of the artist (“male”, “female”, “other”)
            ipi			IPI code for the artist
            sortname	artist sortname
            tag			a tag applied to the artist
            type		artist type (“person”, “group”, "other" or “unknown”)
         *
         */
        if(empty($query) || is_null($query))
            return false;
        else
            return $this->__makeSearchCall('artist', $field, $query, $limit);

    }

    /**
     * @param string $query
     * @param string $field     defaults to 'title'
     * @param int    $limit     defaults to 10, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchCDstubs($query, $field='title',$limit=10)
    {

        /*
         *  FIELD       DESCRIPTION
         *  artist		artist name
            title		release name
            barcode		release barcode
            comment		general comments about the release
            tracks		number of tracks on the CD stub
            discid		disc ID of the CD
         *
         */
        if(empty($query) || is_null($query))
            return false;
        else
            return $this->__makeSearchCall('cdstub', $field, $query, $limit);

    }

    /**
     * @param string $query
     * @param string $field     defaults to blank
     * @param int    $limit     defaults to 10, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchLabel($query, $field='',$limit=10)
    {

        /*
         *
         *  FIELD		DESCRIPTION
            alias		the aliases/misspellings for this label
            area		label area
            begin		label founding date
            code		label code (only the figures part, i.e. without "LC")
            comment		label comment to differentiate similar labels
            country		The two letter country code of the label country
            end			label dissolution date
            ended		true if know ended even if do not know end date
            ipi			ipi
            label		label name
            labelaccent	name of the label with any accent characters retained
            laid		MBID of the label
            sortname	label sortname
            type		label type
            tag			folksonomy tag
         *
         */

        if(empty($query) || is_null($query))
            return false;
        else
            return $this->__makeSearchCall('label', $field, $query, $limit);

    }

    /**
     * @param string $query1
     * @param string $field1    defaults to 'recording', first field to search in query
     * @param string $query2
     * @param string $field2    defaults to 'artist', second field to search to narrow results
     * @param int    $limit     defaults to 30, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchRecording($query1, $field1='recording', $query2='', $field2='artist',$limit=30)
    {

        /*
         *
         *  Field			Description
            arid 			artist id
            artist 			artist name is name(s) as it appears on the recording
            artistname 		an artist on the recording, each artist added as a separate field
            creditname 		name credit on the recording, each artist added as a separate field
            comment 		recording disambiguation comment
            country 		recording release country
            date 			recording release date
            dur 			duration of track in milliseconds
            format 			recording release format
            isrc			ISRC of recording
            number 			free text track number
            position 		the medium that the recording should be found on, first medium is position 1
            primarytype 	primary type of the release group (album, single, ep, other)
            puid			PUID of recording
            qdur 			quantized duration (duration / 2000)
            recording 		name of recording or a track associated with the recording
            recordingaccent name of the recording with any accent characters retained
            reid 			release id
            release 		release name
            rgid 			release group id
            rid 			recording id
            secondarytype 	secondary type of the release group (audiobook, compilation, interview, live, remix soundtrack, spokenword)
            status			Release status (official, promotion, Bootleg, Pseudo-Release)
            tid 			track id
            tnum 			track number on medium
            tracks 			number of tracks in the medium on release
            tracksrelease 	number of tracks on release as a whole
            tag 			folksonomy tag
            type 			type of the release group, old type mapping for when we did not have separate primary and secondary types
         *
         */

        if(empty($query1) || is_null($query1))
            return false;
        else
        {
            $query=$query1.(($query2 != '') ? " AND ".$field2.":".$query2 : '');
            return $this->__makeSearchCall('recording', $field1, $query, $limit);
        }
    }

    /**
     * @param string $query
     * @param string $field     defaults to 'releasegroup'
     * @param int    $limit     defaults to 10, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchReleaseGroup($query, $field='releasegroup',$limit=10)
    {
        /*
         *
         *  Field 				Description
            arid 				MBID of the release group’s artist
            artist 				release group artist as it appears on the cover (Artist Credit)
            artistname 			“real name” of any artist that is included in the release group’s artist credit
            comment 			release group comment to differentiate similar release groups
            creditname 			name of any artist in multi-artist credits, as it appears on the cover.
            primarytype 		primary type of the release group (album, single, ep, other)
            rgid 				MBID of the release group
            releasegroup 		name of the release group
            releasegroupaccent 	name of the releasegroup with any accent characters retained
            releases 			number of releases in this release group
            release 			name of a release that appears in the release group
            reid 				MBID of a release that appears in the release group
            secondarytype 		secondary type of the release group (audiobook, compilation, interview, live, remix soundtrack, spokenword)
            status 				status of a release that appears within the release group
            tag 				a tag that appears on the release group
            type 				type of the release group, old type mapping for when we did not have separate primary and secondary types
         *
         */
        if(empty($query) || is_null($query))
            return false;
        else
            return $this->__makeSearchCall('release-group', $field, $query, $limit);
    }

    /**
     * @param string $query1
     * @param string $field1    defaults to 'release'
     * @param string $query2
     * @param string $field2    defaults to 'artistname', used to narrow results
     * @param int    $limit     defaults to 10, max number of results to return
     *
     * @return bool|SimpleXMLElement
     */
    private function __searchRelease($query1, $field1='release', $query2='', $field2='artistname',$limit=10)
    {
        /*
         *
         *
         *field 			Description
            arid 			artist id
            artist 			complete artist name(s) as it appears on the release
            artistname 		an artist on the release, each artist added as a separate field
            asin 			the Amazon ASIN for this release
            barcode 		The barcode of this release
            catno 			The catalog number for this release, can have multiples when major using an imprint
            comment 		Disambiguation comment
            country 		The two letter country code for the release country
            creditname 		name credit on the release, each artist added as a separate field
            date 			The release date (format: YYYY-MM-DD)
            discids 		total number of cd ids over all mediums for the release
            discidsmedium 	number of cd ids for the release on a medium in the release
            format 			release format
            laid 			The label id for this release, a release can have multiples when major using an imprint
            label 			The name of the label for this release, can have multiples when major using an imprint
            lang 			The language for this release. Use the three character ISO 639 codes to search for a specific language. (e.g. lang:eng)
            mediums 		number of mediums in the release
            primarytype 	primary type of the release group (album, single, ep, other)
            puid 			The release contains recordings with these puids
            reid 			release id
            release 		release name
            releaseaccent 	name of the release with any accent characters retained
            rgid 			release group id
            script 			The 4 character script code (e.g. latn) used for this release
            secondarytype 	secondary type of the release group (audiobook, compilation, interview, live, remix, soundtrack, spokenword)
            status 			release status (e.g official)
            tag 			a tag that appears on the release
            tracks 			total number of tracks over all mediums on the release
            tracksmedium 	number of tracks on a medium in the release
            type 			type of the release group, old type mapping for when we did not have separate primary and secondary types
         *
         *
         */

        if(empty($query1) || is_null($query1))
            return false;
        else
        {
            $query=$query1.(($query2 != '') ? " AND ".$field2.":".$query2 : '');
            return $this->__makeSearchCall('release', $field1, $query, $limit);
        }
    }

    /**
     * @param string    $query
     * @param string    $field      defaults to 'work'
     * @param int       $limit      defaults to 10, max number of results to return
     *
     * @return bool|mixed
     */
    private function __searchWork($query, $field='work',$limit=10)
    {
        /*
         *
         *  Field           Description
            alias 			the aliases/misspellings for this work
            arid 			artist id
            artist 			artist name, an artist in the context of a work is an artist-work relation such as composer or performer
            comment 		disambiguation comment
            iswc 			ISWC of work
            lang 			Lyrics language of work
            tag 			folksonomy tag
            type 			work type
            wid 			work id
            work 			name of work
            workaccent 		name of the work with any accent characters retained
         *
         */

        if(empty($query) || is_null($query))
            return false;
        else
            return $this->__makeSearchCall('work', $field, $query, $limit);

    }

    /**
     * @param array  $musicRow     associative array containing ID, name, searchname
     *
     * @return bool
     */
    public function processMusicRelease($musicRow)
    {
        $nameCleaning = new nameCleaning();
        $db = new DB();

        $failureType = '';
        $n = "\n\033[01;37m";

        if (preg_match('/bootleg/i', $musicRow['name']) === 1)
        {
                echo "Skipping bootleg release: " . $musicRow['name'] . $n;
                return true;
        }

        $cleanSearchName = $nameCleaning->musicCleaner($musicRow['searchname']);
        $query = $this->cleanQuery($cleanSearchName);

        if (preg_match('/\(?(19|20)\d\d\)?(?!.+(19|20)\d\d)(?!kbps|x)/', $musicRow['searchname'], $year) === 0)
            preg_match('/\(?(19|20)\d\d\)?(?!.+(19|20)\d\d)(?!kbps|x)/', $musicRow['name'], $year);

        $artistSearchArray[] = $musicRow['searchname'];
        $artistSearchArray[] = $this->__normalizeString($musicRow['searchname']);
        $artistSearchArray[] = $this->__normalizeString($musicRow['searchname'], true);
        $artistSearchArray[] = $musicRow['name'];
        $artistSearchArray[] = $this->__normalizeString($musicRow['name']);
        $artistSearchArray[] = $this->__normalizeString($musicRow['name'], true);

        $isSingle = $this->isTrack($musicRow['name']);

        if (!$isSingle)
        {
            $artistResult = $this->findArtist($query, $artistSearchArray);
            if ($artistResult)
            {
                $query = trim(preg_replace('/\b' . $artistResult->getMatchString() . '\b/i', '', $query));

                $releaseSearchArr = array();
                $releaseSearchArr = $this->__buildReleaseSearchArray($musicRow['searchname'], $releaseSearchArr);
                $releaseSearchArr = $this->__buildReleaseSearchArray($musicRow['name'], $releaseSearchArr);

                $albumResult = $this->findRelease($query, $artistResult, $releaseSearchArr, (isset($year[0]) ? $year[0] : null));
                if ($albumResult)
                {
                    if($this->updateArtist($artistResult))
                    {
                        if($this->updateAlbum($albumResult))
                        {
                            echo "\033[01;32mAdded/Updated Album: " . $albumResult->getTitle() . "  Artist: " . $artistResult->getName() . $n;
                            $db->queryDirect("UPDATE releases SET musicinfoID=99999999, mbAlbumID=" . $db->escapeString($albumResult->getMbID()) .
                                                ", mbTrackID=NULL WHERE ID=" . $musicRow['ID']);
                            return true;
                        }
                        else
                            echo "\033[01;31mERROR: Encountered an error adding/updating album" . $n;
                    }
                    else
                        echo "\033[01;31mERROR: Encountered an error adding/updating artist" . $n;
                }
                else
                {
                    echo "\033[01;33mUnable to match release: " . $musicRow['searchname'] . $n;
                    $failureType = 'release-release';
                }
            }
            else
            {
                echo "\033[01;34mUnable to determine artist: " . $musicRow['searchname'] . $n;
                $failureType = 'release-artist';
            }
        }
        else
        {
            $prefix = isset($isSingle['disc']) ? (string)$isSingle['disc'] . (string)$isSingle['track'] : $isSingle['track'];
            $query = preg_replace('/^' . $prefix . '/', '', $query);

            $artistResult = $this->findArtist((isset($isSingle['artist']) ? $isSingle['artist'] : $query), $artistSearchArray);
            if ($artistResult)
            {
                if (isset($year[0]))
                    $isSingle['year'] = $year[0];

                $recordingResult = $this->findRecording($isSingle, $artistResult, false);
                if ($recordingResult)
                {
                    if($this->updateArtist($artistResult))
                    {
                        if($this->updateTrack($recordingResult['recording']))
                        {
                            if($recordingResult['release'] !== false)
                            {
                                if($this->updateAlbum($recordingResult['release']))
                                {
                                    echo "\033[01;32mAdded/Updated Album: " . $recordingResult['release']->getTitle() . "  Artist: " . $artistResult->getName() . $n;
                                    echo "\033[01;32mAdded/Update Track: " . $recordingResult['recording']->getTitle() . "  Artist: " . $artistResult->getName() . $n;
                                    $db->queryDirect("UPDATE releases SET musicinfoID=99999999, mbAlbumID=" . $db->escapeString($recordingResult['release']->getMbID()) .
                                        ", mbTrackID=" . $db->escapeString($recordingResult['recording']->getMbID()) . " WHERE ID=" . $musicRow['ID']);
                                    return true;
                                }
                            }
                            else
                            {
                                echo "\033[01;32mAdded/Update Track: " . $recordingResult['recording']->getTitle() . "  Artist: " . $artistResult->getName() . $n;
                                $db->queryDirect("UPDATE releases SET musicinfoID=99999999, mbAlbumID=NULL" .
                                    ", mbTrackID=" . $db->escapeString($recordingResult['recording']->getMbID()) . " WHERE ID=" . $musicRow['ID']);
                                return true;
                            }
                        }
                        else
                            echo "\033[01;31mERROR: Encountered an error updating track" .$n;
                    }
                    else
                        echo "\033[01;31mERROR: Encountered an error updating artist" . $n;
                }
                else
                {
                    echo "\033[01;33mUnable to match single: " . $isSingle['title'] . $n;
                    $failureType = 'track-track';
                }
            }
            else
            {
                echo "\033[01;33mUnable to match artist: " . $isSingle['artist'] . $n;
                $failureType = 'track-artist';
            }
        }

        if(MusicBrainz::WRITE_LOG_FILES)
        {
            $log = $musicRow['ID'] . ',"' . $musicRow['name'] . '","' . $musicRow['searchname'] . '"' . "\n";
            file_put_contents($this->_baseLogPath . $failureType . '-noMatch.log', $log, FILE_APPEND);
        }

        return false;
    }

    /**
     * @param string       $query       Search string to be sent to MusicBrainz
     * @param string|array $searchArray String or array of strings that results should be matched against
     *
     * @return mbArtist|bool
     */
    public function findArtist($query, $searchArray)
    {
        $mbArtist = new mbArtist();
        $foundArtist = false;

        if (!is_array($searchArray))
        {
            $temp = $searchArray;
            unset($searchArray);
            $searchArray = array();
            $searchArray[] = $temp;
            $searchArray[] = $this->__normalizeString($temp);
            $searchArray[] = $this->__normalizeString($temp, true);
        }


        $wordCount = count(explode(' ', $query));
        if ($query == 'VA')
        {
            $mbArtist->setName('Various Artists');
            $mbArtist->setMbID('89ad4ac3-39f7-470e-963a-56509c546377');
            $mbArtist->setMatchString('VA');
            $mbArtist->setPercentMatch(100);
            return $mbArtist;
        }

        $results = $this->__searchArtist($query, '', 50);

        $resultsAttr = isset($results->{'artist-list'}) ? $results->{'artist-list'}->attributes() : array();
        if (isset($resultsAttr['count']) && $resultsAttr['count'] == '0')
        {
            if (MusicBrainz::DEBUG_MODE)
                echo "Artist name search returned no results\n";
            return false;
        }
        elseif (!isset($resultsAttr['count']))
        {
            if(MusicBrainz::DEBUG_MODE)
                print_r($results);
            return false;
        }
        elseif (MusicBrainz::DEBUG_MODE)
            echo "Artists Found: " . $resultsAttr['count'] . "\n";

        $percentMatch = -1000;

        $i = 0;
        foreach ($results->{'artist-list'}->artist as $artist)
        {

            $artistCheck = $this->__checkArtistName($artist, $searchArray, false, (((30 - $i) / 30) * 10));
            if ($artistCheck && $artistCheck->getPercentMatch() > $percentMatch)
            {
                // The following helps to prevent single-word artists from matching an artist
                // with a similar full name (i.e Pink should not match Pink Floyd)
                // Obviously only works if the query string is two words or less
                if ($wordCount < 3 && count(explode(' ', $artistCheck['name'])) != $wordCount)
                {
                    if (DEBUG_ECHO)
                        echo "Matching artist name too short: " . $artistCheck['name'] . "\n";
                    continue;
                }
                $mbArtist = $artistCheck->getMbID();
                $percentMatch = $artistCheck->getPercentMatch();
                $foundArtist = true;
            }
            $i++;
        }
        $mbArtist = $foundArtist === true ? $this->__getArtistDetails($mbArtist) : false;

        return $foundArtist === true ? $mbArtist : false;
    }

    /**
     * @param string       $query       searchname after musicCleaner and cleanQuery
     * @param mbArtist     $artist      mbArtist or false
     * @param array        $searchArray array of strings to compare results against
     * @param integer|null $year        Year of release
     *
     * @return mbRelease|bool
     *
     * NOTE: If an artist is provided, better results will be obtained if the artist
     * name is removed from the $query string
     */
    public function findRelease($query, mbArtist $artist, $searchArray, $year = null)
    {
        // enforce artist requirement
        // check all occurrences of $searchArray, fix $searchNameArr to use $searchArray

        $query = $this->__normalizeString($query);

        $percentMatch = 0;

        $mbArtist = new mbArtist();
        $matchedRelease = array();


        $foundRelease = false;

        if ($artist === false)
        {
            $results = $this->__searchRelease($query, 'release', '', '', 30);
        }
        else
        {
            $results = $this->__searchRelease($query, 'release', $this->__normalizeString($artist->getName()), 'artistname');
        }
        if (!isset($results->{'release-list'}->attributes()->count))
        {
            if(MusicBrainz::DEBUG_MODE)
                print_r($results);
            return false;
        }
        if ($results->{'release-list'}->attributes()->count == '0')
        {
            if (MusicBrainz::DEBUG_MODE)
                echo "Release name search returned no results\n";

            return false;
        }
        else
            if (MusicBrainz::DEBUG_MODE)
                echo "Releases Found: " . $results->{'release-list'}->attributes()->count . "\n";

        if ($results->{'release-list'}->attributes()->count == '1')
        {
            $matchFound = false;
            foreach ($searchArray as $searchName)
            {
                if (stripos($searchName, $this->__normalizeString($results->{'release-list'}->release->title)) === false &&
                    stripos($this->__normalizeString($searchName, true), $this->__normalizeString($results->{'release-list'}->release->title, true)) === false)
                    continue;
                else
                {
                    $matchFound = true;
                    break;
                }
            }
            if (!$matchFound)
            {
                if (MusicBrainz::DEBUG_MODE)
                    echo "Non-matching release: " . $results->{'release-list'}->release->title . "\n";

                return false;
            }
            else
            {
                $matchedRelease['id'] = $results->{'release-list'}->release->attributes()->id;
                $matchedRelease['percentMatch'] = 100;
                $matchedRelease['artistID'] = $artist->getMbID();
                $foundRelease = true;
            }
        }
        else // More than 1 release was found
        {
            foreach ($results->{'release-list'}->release as $release)
            {
                $matchFound = false;
                $matchedSearchName = '';
                foreach ($searchArray as $searchName)
                {
                    if (stripos($searchName, $this->__normalizeString($release->title)) === false &&
                        stripos($this->__normalizeString($searchName, true), $this->__normalizeString($release->title, true)) === false
                    )
                        continue;
                    else
                    {
                        $matchedSearchName = $searchName;
                        $matchFound = true;
                        break;
                    }
                }
                if (!$matchFound)
                {
                    if (MusicBrainz::DEBUG_MODE)
                        echo "Non-matching release: " . $release->title . "\n";
                    continue;
                }
                else
                {
                    similar_text($this->__normalizeString($release->title), $matchedSearchName, $tempMatch);
                    if (MusicBrainz::DEBUG_MODE)
                        echo "Checking release: " . $release->title . "\n";
                    if (!$artist && isset($release->{'artist-credit'}->{'name-credit'}))
                    {
                        $i = 0;  // Counter for position in list of artists
                        foreach ($release->{'artist-credit'}->{'name-credit'} as $relArtist)
                        {
                            if (isset($relArtist->name))
                                $mbArtist = $this->__checkArtistName($relArtist, $searchArray, false, (((30 - $i) / 30) * 10));
                            else
                                $mbArtist = $this->__checkArtistName($relArtist->artist, $searchArray, false, (((30 - $i) / 30) * 10));
                            if ($mbArtist && stripos($query, $this->__normalizeString($mbArtist->getName())) !== false)
                            {
                                $tempMatch += 25;
                                break;
                            }
                            else
                                $mbArtist = false;
                            $i++;
                        }
                        if (!$mbArtist)
                        {
                            if (MusicBrainz::DEBUG_MODE)
                                echo "No matching artist was found in the release.\n";
                            continue;
                        }
                        elseif ($mbArtist->getName() == 'Various Artists')
                            $tempMatch -= 15;
                    }
                    elseif($artist)
                        $mbArtist = $artist;
                    if ($this->__normalizeString($release->title, true) == $this->__normalizeString($mbArtist->getName(), true) &&
                        substr_count($query, $this->__normalizeString($mbArtist->getName(), true)) == 1)
                    {
                        if (MusicBrainz::DEBUG_MODE)
                            echo "Artist name and release title are the same, but not looking for self-titled release\n";
                        continue;
                    }
                    elseif (stripos(trim(preg_replace('/' . $this->__normalizeString($mbArtist->getName(), true) . '/', '', $this->__normalizeString($matchedSearchName, true), 1)),
                            trim($this->__normalizeString($release->title, true))) === false)
                    {
                        if (MusicBrainz::DEBUG_MODE)
                            echo "Title no longer matched after extracting artist's name.\n";
                        continue;
                    }
                    if (isset($release->date) && !is_null($year) && preg_match('/' . $year . '/', $release->date))
                        $tempMatch += 25;
                    elseif (isset($release->date) && !is_null($year))
                    {
                        preg_match('/(19|20)\d\d', $release->date, $relYear);
                        if (isset($relYear[0]) && ($relYear[0] == ($year - 1) || $relYear[0] == ($year + 1)))
                            $tempMatch += 20;
                    }
                    elseif (isset($release->{'release-event-list'}->{'release-event'}->date) && !is_null($year) && $release->{'release-event-list'}->{'release-event'}->date == $year)
                        $tempMatch += 25;
                    elseif (isset($release->{'release-event-list'}->{'release-event'}->date) && !is_null($year))
                    {
                        preg_match('/(19|20)\d\d', $release->{'release-event-list'}->{'release-event'}->date, $relYear);
                        if ($relYear[0] == ($year - 1) || $relYear[0] == ($year + 1))
                            $tempMatch += 20;
                    }
                    if (isset($release->{'medium-list'}->medium->format) && $release->{'medium-list'}->medium->format == 'CD')
                        $tempMatch += 15;
                    if (MusicBrainz::DEBUG_MODE)
                        echo "Matching release: " . $release->title . " tempMatch: " . $tempMatch . "\n";
                    if ($tempMatch > $percentMatch)
                    {
                        $matchedRelease['id'] = $release->attributes()->id;
                        $matchedRelease['percentMatch'] = $tempMatch;
                        $matchedRelease['artistID'] = $mbArtist->getMbID();
                        $percentMatch = $tempMatch;
                        $foundRelease = true;
                        unset($mbArtist);

                    }
                }
            }
        }
        $mbRelease = $foundRelease === true ? $this->__getReleaseDetails($matchedRelease['id'], $matchedRelease['artistID'], $matchedRelease['percentMatch']) : false;
        return $foundRelease === true ? $mbRelease : false;
    }

    /**
     * @param array     $query               query array containing title (required), release, track, year
     * @param mbArtist  $artist              required
     * @param bool      $requireReleaseMatch Whether or not to only match the title if the release matches as well
     *                                       Defaults to false
     *
     * @return array|bool                    {recording => mbTrack, release => mbRelease or false if no release matched}
     *                                       or return false on no matches
     */
    public function findRecording($query, mbArtist $artist,  $requireReleaseMatch = false)
    {
        $foundRecording = false;
        $return = array();
        $matchedRecording = array();

        if(!isset($artist))
            return false;

        if (is_array($query) && isset($query['title']))
        {
            // Experimental - remove text inside parenthesis.  Usually contains a second artist, i.e. (featuring John Doe) that
            // seems to cause a lot of non-matches, or mismatches
            $query['title'] = preg_replace('/\([\w\s\.\-]+\)/', '', $query['title']);
            $results = $this->__searchRecording($query['title'], 'recording', $artist->getName(), 'artistname');
        }
        else
            return false;

        if (!isset($results->{'recording-list'}->attributes()->count))
        {
            if(MusicBrainz::DEBUG_MODE)
                print_r($results);
            return false;
        }
        if ($results->{'recording-list'}->attributes()->count == '0')
        {
            if (MusicBrainz::DEBUG_MODE)
                echo "Recording search returned no results\n";

            return false;
        } elseif (MusicBrainz::DEBUG_MODE)
            echo "Recordings Found: " . $results->{'recording-list'}->attributes()->count . "\n";

        $normalizedTitleArr = array();
        $normalizedTitleArr[] = $this->__normalizeString($query['title']);
        $normalizedTitleArr[] = $this->__normalizeString($query['title'], true);

        $i = 0; // Recording result counter, used for weighting results
        $percentMatch = -1000; // Arbitrary starting value for $percentMatch
        foreach ($results->{'recording-list'}->recording as $recording)
        {
            $matchFound = false;

            foreach ($normalizedTitleArr as $normalizedTitle)
            {
                if (MusicBrainz::DEBUG_MODE)
                {
                    echo "Checking Title: " . $normalizedTitle . "\n";
                    echo "Against Title:  " . $this->__normalizeString($recording->title) . "\n";
                }
                if (stripos($normalizedTitle, $this->__normalizeString($recording->title)) === false &&
                    stripos($normalizedTitle, $this->__normalizeString($recording->title, true)) === false &&
                    stripos($this->__normalizeString($recording->title), $normalizedTitle) === false &&
                    stripos($this->__normalizeString($recording->title, true), $normalizedTitle) === false &&
                    $normalizedTitle != $this->__normalizeString($recording->title)
                )
                    continue;
                else
                {
                    $matchFound = true;
                    break;
                }
            }
            if ($matchFound)
            {
                // Check for a matching release for the recording
                $releaseMatchFound = false;
                if (isset($query['release']) && isset($recording->{'release-list'}))
                {
                    if ($mbRelease = $this->__getRecordingRelease($query, $recording->{'release-list'})) // release loop
                        $releaseMatchFound = true;
                }
                else // query['release'] is not set, or there was not a release list in the results
                {
                    $releaseMatchFound = true; //Simplifies coding to fake a release match
                    $mbRelease = false; // But the release array won't contain anything
                }
                if (!$releaseMatchFound && $requireReleaseMatch)
                {
                    if (MusicBrainz::DEBUG_MODE)
                        echo "No matching release for matched title.\n";
                    continue;
                }
                else
                {
                    similar_text((isset($query['title']) ? $query['title'] : $query), $recording->title, $tempPercentMatch);
                    $tempPercentMatch += (((30 - $i) / 30) * 10); // matches weighted based on position in results list
                    $tempPercentMatch += ($releaseMatchFound && isset($recording->{'release-list'}) ? 15 : 0); //Weight recordings for which the release matched
                    if ($tempPercentMatch > $percentMatch)
                    {
                        $matchedRecording['id'] = $recording->attributes()->id;
                        $matchedRecording['percentMatch'] = $tempPercentMatch;
                        $matchedRecording['releaseID'] = $mbRelease !== false ? $mbRelease->getMbID() : false;
                        unset($mbRelease);
                        $foundRecording = true;
                    }
                } // Release match is true
            } // Title match found
            else
            {
                if (MusicBrainz::DEBUG_MODE)
                    echo "Non-matching recording title: " . $recording->title . "\n";
            }
            $i++; // Increment the recording result counter
        } // Recording result loop

        if(MusicBrainz::DEBUG_MODE)
        {
            ob_start();
            print_r($results);
            $resultsString = ob_get_clean();
            file_put_contents(WWW_DIR . 'lib/logging/vardump/' . $query['releaseID'] . '-' . $query['artist'] . '-' . $query['title'] . '.log', $resultsString);
        }
        if($foundRecording)
        {
            $return['recording'] = $this->__getRecordingDetails($matchedRecording['id'], $artist->getMbID(),
                ($matchedRecording['releaseID'] !== false ? $matchedRecording['releaseID'] : null), $matchedRecording['percentMatch']);
            $return['release'] =  $matchedRecording['releaseID'] !== false ? $this->__getReleaseDetails($matchedRecording['releaseID'], $artist->getMbID()) : false;
        }

        return $foundRecording === true ? $return : false;
    }

    /**
     * @param string $text              string to normalize
     * @param bool   $includeArticles   remove English language articles (a, an, the)
     *
     * @return string
     *
     * This function standardizes text strings to facilitate better matches
     */
    private function __normalizeString($text, $includeArticles = false)
    {
        $text = strtolower($text);
        if ($includeArticles)
            $text = preg_replace('/\b(a|an|the)\b/i', ' ', $text);
        $text = str_replace(array(".", "_", '-', "|", "<", ">", '"', "=", "~", '[', "]", "(", ")", "{", "}", "*", ";", ":", ",", "~", "/", "+", "'s "), " ", $text);
        $text = str_ireplace(' vol ', ' Volume ', $text);
        $text = str_ireplace('&', 'and', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * @param string    $text   text to clean
     * @param bool      $debug  true to output debug info, defaults to false
     *
     * @return string
     */
    public function cleanQuery($text, $debug = false)
    {
        // Remove year
        if ($debug)
            echo "\nStrip Search Name - " . $text . "\n";
        $text = preg_replace('/\((19|20)\d\d\)|(?<!top|part|vol|volume)[ \-_]\d{1,3}[ \-_]|\d{3,4} ?kbps| cd ?\d{1,2} /i', ' ', $text);
        if ($debug)
            echo "1 - " . $text . "\n";
        // Remove extraneous format identifiers
        $text = str_replace(array('MP3', 'FLAC', 'WMA', 'WEB', "cd's", ' cd ', ' FM '), ' ', $text);
        if ($debug)
            echo "2 - " . $text . "\n";
        $text = str_ireplace(' vol ', ' Volume ', $text);
        if ($debug)
            echo "3 - " . $text . "\n";
        // Remove extra punctuation and non alphanumeric
        $text = str_replace(array(".", "_", '-', "|", "<", ">", '"', "=", "~", '[', "]", "(", ")", "{", "}", "*", ";", ":", ",", "~", "/", "+", "!"), " ", $text);
        if ($debug)
            echo "4 - " . $text . "\n";
        $text = preg_replace('/\s{2,}/', ' ', $text);
        if ($debug)
            echo "5 - " . $text . "\n";

        return $text;
    }

    /**
     * @param string $text        text to use as base
     * @param array  $searchArray array to append results to
     *
     * @return array
     *
     * This function builds an array of strings based on rules defined within the
     * function.  The array is then used to compare release search results against.
     */
    private function __buildReleaseSearchArray($text, $searchArray)
    {
        $searchArray[] = $text;
        $searchArray[] = $this->__normalizeString($text);
        $searchArray[] = $this->__normalizeString($text, true);

        // Remove the word "volume" because many entries in MusicBrainz don't include it
        // i.e. instead of Great Music Volume 1, MB will have Great Music 1
        if (preg_match('/\bVolume\b/i', $text))
            $searchArray[] = preg_replace('/\bVolume\b/i', ' ', $text);
        // Replace ordinal numbers with roman numerals
        preg_match('/\bVolume[ \-_\.](\d)\b/i', $text, $matches);
        switch ($matches[1])
        {
            case '1':
                $searchArray[] = preg_replace('\bVolume[ \-_\.]1\b', ' Volume I ', $text);
                $searchArray[] = preg_replace('\bVolume[ \-_\.]1\b', ' I ', $text);
                break;
            case '2':
                $searchArray[] = preg_replace('\bVolume[ \-_\.]2\b', ' Volume II ', $text);
                $searchArray[] = preg_replace('\bVolume[ \-_\.]2\b', ' II ', $text);
                break;
            case '3':
                $searchArray[] = preg_replace('\bVolume[ \-_\.]3\b', ' Volume III ', $text);
                $searchArray[] = preg_replace('\bVolume[ \-_\.]3\b', ' III ', $text);
                break;
            case '4':
                $searchArray[] = preg_replace('\bVolume[ \-_\.]4\b', ' Volume IV ', $text);
                $searchArray[] = preg_replace('\bVolume[ \-_\.]4\b', ' IV ', $text);
                break;
            case '5':
                $searchArray[] = preg_replace('\bVolume[ \-_\.]5\b', ' Volume V ', $text);
                $searchArray[] = preg_replace('\bVolume[ \-_\.]5\b', ' V ', $text);
                break;
        }

        // Get rid of extra spaces in all values
        foreach ($searchArray as $key => $value)
        {
            $searchArray[$key] = preg_replace('/\s{2,}/', ' ', $value);
        }

        return $searchArray;
    }

    /**
     * @param mbArtist $artist
     *
     * @return bool
     */
    public function updateArtist(mbArtist $artist)
    {
        if($artist->getMbID() == '' || is_null($artist->getMbID()))
            return false;

        $this->__updateGenres('artist', $artist->getMbID(), $artist->getTags());
        $db = new DB();
        $searchExisting = $db->queryOneRow("SELECT mbID FROM mbArtists WHERE mbID=" . $db->escapeString($artist->getMbID()));
        if(!$searchExisting)
        {
            $sql = "INSERT INTO mbArtists (mbID, name, type, gender, disambiguation, description, genreID, country, rating, beginDate, endDate) VALUES (" .
                     $db->escapeString($artist->getMbID()) . ", " . $db->escapeString($artist->getName()) . ", " . $db->escapeString($artist->getType()) . ", " .
                     $db->escapeString($artist->getGender()) . ", " . $db->escapeString($artist->getDisambiguation()) . ", " .
                     $db->escapeString($artist->getDescription()) . ", " . $db->escapeString(implode(", ", $artist->getTags())) . ", " . $db->escapeString($artist->getCountry()) . ", " .
                     $artist->getRating() . "," . $db->escapeString($artist->getBeginDate()) . "," . $db->escapeString($artist->getEndDate()) . ")";

            if(MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'artist-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryInsert($sql);
        }
        else
        {
            $sql = "UPDATE mbArtists SET name=" . $db->escapeString($artist->getName()) . ", type=" . $db->escapeString($artist->getType()) . ", description=" . $db->escapeString($artist->getDescription()) .
                    ", gender=" . $db->escapeString($artist->getGender()) . ", disambiguation=" . $db->escapeString($artist->getDisambiguation()) .
                    ", genres=" . $db->escapeString(implode(", ", $artist->getTags())) . ", country=" . $db->escapeString($artist->getCountry()) . ", rating=" . $artist->getRating() .
                    ", beginDate=" . $db->escapeString($artist->getBeginDate()) . ", endDate=" . $db->escapeString($artist->getEndDate()) . ", updateDate=" . time() .
                    " WHERE mbID=" . $db->escapeString($artist->getMbID());

            if (MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'artist-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryDirect($sql);
        }
    }

    /**
     * @param mbRelease $release
     *
     * @return bool
     */
    public function updateAlbum(mbRelease $release)
    {
        if($release->getMbID() == '' || is_null($release->getMbID()))
            return false;

        $this->__updateGenres('album', $release->getMbID(), $release->getTags());
        $db = new DB();
        $searchExisting = $db->queryOneRow("SELECT mbID FROM mbAlbums WHERE mbID=" . $db->escapeString($release->getMbID()));
        if(!$searchExisting)
        {
            $this->__getCoverArt($release);

            $sql = "INSERT INTO mbAlbums (mbID, artistID, title, year, status, country, releaseDate, releaseGroupID, description, tracks, genres, cover, rating, asin) VALUES " .
                    "(" . $db->escapeString($release->getMbID()) . ", " . $db->escapeString($release->getArtistID()) .
                    ", " . $db->escapeString($release->getTitle()) . ", " . $db->escapeString($release->getYear()) .
                    ", " . $db->escapeString($release->getStatus()) . ", " . $db->escapeString($release->getCountry()) .
                    ", " . $db->escapeString($release->getReleaseDate()) . ", " . $db->escapeString($release->getReleaseGroupID()) .
                    ", " . $db->escapeString($release->getDescription()) . ", " . $release->getTracks() .
                    ", " . $db->escapeString(implode(", ", $release->getTags())) . ", " . $db->escapeString($release->getCover()) .
                    ", " . $release->getRating() . ", " . ", " . $db->escapeString($release->getAsin());



            if (MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'album-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryInsert($sql);
        }
        else
        {
            $this->__getCoverArt($release);

            $sql = "UPDATE mbAlbums SET artistID=" . $db->escapeString($release->getArtistID()) . ", title=" . $db->escapeString($release->getTitle()) .
                    ", year=" . $db->escapeString($release->getYear()) . ", releaseDate=" . $db->escapeString($release->getReleaseDate()) .
                    ", status=" . $db->escapeString($release->getStatus()) . ", country=" . $db->escapeString($release->getCountry()) .
                    ", releaseGroupID=" . $db->escapeString($release->getReleaseGroupID()) . ", description=" . $db->escapeString($release->getDescription()) .
                    ", tracks=" . $release->getTracks() . ", genres=" . $db->escapeString(implode(", ", $release->getTags())) .
                    ", cover=" . $db->escapeString($release->getCover()) . ", rating=" . $release->getRating() .
                    ", asin=" . $db->escapeString($release->getAsin()) . ", updateDate=" . time() . " WHERE mbID=" . $db->escapeString($release->getMbID());

            if (MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'album-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryDirect($sql);
        }
    }

    /**
     * @param mbTrack $track
     *
     * @return bool
     */
    public function updateTrack(mbTrack $track)
    {
        if($track->getMbID() == '' || is_null($track->getMbID()))
            return false;

        $db = new DB();
        $searchExisting = $db->queryOneRow("SELECT mbID from mbTracks WHERE mbID=" . $db->escapeString($track->getMbID()));
        if(!$searchExisting)
        {
            $sql = "INSERT INTO mbTracks (mbID, albumID, artistID, year, trackNumber, discNumber, title, length) VALUES " .
                "(" . $db->escapeString($track->getMbID()) . ", " . $db->escapeString($track->getAlbumID()) .
                ", " . $db->escapeString($track->getArtistID()) . ", " . $track->getYear() . ", " . $track->getTrackNumber() .
                ", " . $db->escapeString($track->getDiscNumber()) . ", " . $db->escapeString($track->getTitle()) . ", " . $track->getLength();

            if (MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'track-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryInsert($sql);
        }
        else
        {
            $sql = "UPDATE mbTracks SET albumID=" . $db->escapeString($track->getAlbumID()) . ", artistID=" . $db->escapeString($track->getArtistID()) .
                ", year=" . $track->getYear() . ", trackNumber=" . $track->getTrackNumber() . ", title=" . $db->escapeString($track->getTitle()) .
                ", length=" . $track->getLength() . ", discNumber=" . $track->getDiscNumber() . ", updateDate=" . time() . " WHERE mbID=" . $db->escapeString($track->getMbID());

            if (MusicBrainz::WRITE_LOG_FILES)
                file_put_contents($this->_baseLogPath . 'track-SQL.log', $sql . "\n----------------------------------------\n", FILE_APPEND);

            return $db->queryDirect($sql);
        }
    }

    /* @param string    $type   string literal: 'album' or 'artist'
     * @param string    $mbID   mbID of entity to update
     * @param array     $genres array of tags to update
     *
     * @return bool             true for success, false for failure
     */
    private function __updateGenres($type, $mbID, $genres)
    {
        $validTypes = array('artist', 'album');
        if(in_array($type, $validTypes) && !empty($genres) && !empty($mbID))
        {
            $db = new DB();
            foreach($genres as $genre)
            {
                $genreID = $db->queryOneRow("SELECT ID FROM mbGenres WHERE name=" . $db->escapeString(trim($genre)));
                if(!isset($genreID['ID']))
                    $genreID['ID'] = $db->queryInsert("INSERT INTO mbGenres (name) VALUES (" . trim($genre) . ")");

                $db->queryInsert("INSERT INTO mb" . ucwords($type) . "IDtoGenreID (" . strtolower($type) . "ID, genreID) VALUES (" .
                    $db->escapeString($mbID) . ", " . $genreID['ID'] . ")");
            }
            return true;
        }

        return false;
    }

    /* @param string    $releaseName
     *
     * @return array|bool               array will contain artist and title at minimum
     *                                  may also include release, track, and disc
     */
    public function isTrack($releaseName)
    {
        if (empty($releaseName) || $releaseName == null)
            return false;

        // Remove years and file/part counts
        $releaseName = trim(preg_replace('/\(?\[?19\d\d\]?\)?|\(?\[?20\d\d\]?\)?|\(?\[?\d\d?\/\d\d?\]?\)?/', '', $releaseName));
        // Perform some very basic cleaning on the release name before matching
        $releaseName = trim(preg_replace('/by req:? |attn:? [\w\s_]+| 320 | EAC/i', '', $releaseName));
        // Normalize spacing
        $releaseName = trim(preg_replace('/\s{2,}/', ' ', $releaseName));

        // echo "Cleaned Single Release Name: " . $releaseName . "\n";

        // If it's a blatantly obvious 'various artist' release, use the following pattern
        if (substr($releaseName, 0, 2) == 'VA')
            preg_match('/VA ?- ?(?P<release>[\w\s\' ]+?)- ?(19\d\d|20\d\d)? ?-?(?![\(\[ ](19\d\d|20\d\d))(?P<track> ?(?!\(|\[|19\d\d|20\d\d)[0-2][0-9]\d?\d?) ?- ?(?P<artist>[\w\s\'\.]+?) ?- ?(?P<title>[\(\)\w _\']+)\.(?:mp3|wav|ogg|wma|mpa|aac|m4a|flac)/', $releaseName, $matches);
        else
        {
            // The 'track' group will not match tracks numbered above 19 to prevent matching a year
            // Probably won't be much of an issue because track numbers that high are rare.
            // The alternative is the regex would be much more strict in what would be identified as a track number.
            preg_match('/(?:^|["\- ])(?P<artist>[\w\s\'_]+)[ \-]*?[ \-]*?(?P<release>[\w\s\'_\(\)\-\d]+)[ \-"]*?(?P<track> ?(?!\(|\[|19\d\d|20\d\d)[0-2][0-9]\d?\d?).+?(?!-)(?P<title>[\(\)\w _\']+)\.(?:mp3|wav|ogg|wma|mpa|aac|m4a|flac)/i', $releaseName, $matches);
        }
        if (!isset($matches[0]) || (!isset($matches['artist']) && !isset($matches['release']) && !isset($matches['track']) && !isset($matches['title'])))
            preg_match('/(?P<track>["\- ](?!\(|\[|19\d\d|20\d\d)[0-2][0-9]\d?\d?)(?<!\(|\[|19\d\d|20\d\d)(?P<artist>( |-).+-)* ?-? ?(?P<title>[\(\)\w \-_\']+)\.(?:mp3|wav|ogg|wma|mpa|aac|m4a|flac)/i', $releaseName, $matches);

        if (!isset($matches[0]))
            preg_match('/("|-) ?"?(?P<artist> ?.+-)* ?-? ?(?P<title>[\(\)\w \-_\']+)\.(?:mp3|wav|ogg|wma|mpa|aac|m4a|flac)/i', $releaseName, $matches);

        if (!isset($matches[0]))
            return false;

        if (isset($matches['artist']))
        {
            $matches['artist'] = trim(str_ireplace(array('-', '_', '"'), ' ', $matches['artist']));
            $matches['artist'] = preg_replace('/\s{2,}/', ' ', $matches['artist']);
            if (preg_match('/^\d+$/', $matches['artist']))
                return false;
        }

        if (isset($matches['release']))
        {
            $matches['release'] = trim(str_ireplace(array('-', '_', '"'), ' ', $matches['release']));
            $matches['release'] = trim(preg_replace('/- ?\([\w\d\s]+\) ?-/', '', $matches['release']));
            $matches['release'] = trim(preg_replace('/\s{2,}/', ' ', $matches['release']));
        }

        if (isset($matches['title']))
        {
            $matches['title'] = trim(str_ireplace(array('-', '_', '"'), ' ', $matches['title']));
            $matches['title'] = trim(preg_replace('/\s{2,}/', ' ', $matches['title']));
        }
        if (isset($matches['track']))
            $matches['track'] = trim(str_ireplace(array('"', ' ', '-'), '', $matches['track']));

        if (isset($matches['track']) && strlen($matches['track']) > 2)
        {
            $matches['disc'] = strlen($matches['track']) > 3 ? substr($matches['track'], 0, 2) : substr($matches['track'], 0, 1);
            $matches['track'] = strlen($matches['track']) > 3 ? substr($matches['track'], 2, 2) : substr($matches['track'], 1, 2);
        }


        return (isset($matches['artist']) && isset($matches['title'])) ? $matches : false;
    }

    /**
     * @param simpleXMLElement     $relArtist        simpleXMLElement containing a single artist result from MB
     * @param array                $query            Array containing values to compare against
     * @param bool                 $skipVariousCheck Defaults to false, skip check for Various Artists
     * @param int|float            $weight           Precalculated weight to add to percentMatch
     *
     * @return mbArtist
     */
    private function __checkArtistName($relArtist, $query, $skipVariousCheck = false, $weight = 0)
    {
        $queryText = '';
        if (MusicBrainz::DEBUG_MODE)
            echo "Checking artist: " . $relArtist->name . "\n";
        $percentMatch = 0;
        $artistArr = array();
        $artistFound = false;
        if ($relArtist->name === '[unknown]')
            return false;
        elseif ($relArtist->name == 'Various Artists' && !$skipVariousCheck)
        {
            $artistArr['name'] = 'Various Artists';
            $artistArr['id'] = '89ad4ac3-39f7-470e-963a-56509c546377';
            $artistFound = 'Various Artists';
            $queryText = $query[0];
        }

        foreach ($query as $stringToMatch)
        {
            $queryText = $stringToMatch;
            if (preg_match('/\b' . $this->__normalizeString($relArtist->name) . '\b/i', $stringToMatch) === 0 &&
                preg_match('/\b' . $this->__normalizeString($relArtist->name, true) . '\b/i', $stringToMatch) === 0)
            {
                if (preg_match('/\b' . trim(str_ireplace('Group', '', $this->__normalizeString($relArtist->name, true))) . '\b/i', $stringToMatch) === 1)
                {
                    $artistFound = trim(str_ireplace('Group', '', $this->__normalizeString($relArtist->name, true)));
                    break;
                } 
                elseif (isset($relArtist->{'sort-name'}) && preg_match('/\b' . $this->__normalizeString($relArtist->{'sort-name'}) . '\b/i', $stringToMatch) === 1)
                {
                    $artistFound = $this->__normalizeString($relArtist->{'sort-name'});
                    break;
                } 
                else
                {
                    if (MusicBrainz::DEBUG_MODE)
                        echo "Artist name not matched: " . $relArtist->name . " (weight = $weight)\n";
                    if (isset($relArtist->{'alias-list'}))
                    {
                        if (MusicBrainz::DEBUG_MODE)
                            echo "Checking aliases...\n";
                        foreach ($relArtist->{'alias-list'}->alias as $alias)
                        {
                            if (is_array($alias))
                            {
                                if (MusicBrainz::DEBUG_MODE)
                                    echo "\nAlias is an array\n";
                                foreach ($alias as $aliasName)
                                {
                                    if (isset($aliasName['locale']) && $aliasName->attributes()->locale == 'ja')
                                        continue;
                                    if (preg_match('/\b' . $this->__normalizeString($aliasName) . '\b/i', $stringToMatch) === 0)
                                    {
                                        if (MusicBrainz::DEBUG_MODE)
                                            echo "Alias did not match: " . $aliasName . " (weight = $weight)\n";
                                        continue;
                                    } 
                                    else
                                    {
                                        // if(MusicBrainz::DEBUG_MODE)
                                        $artistFound = $this->__normalizeString($aliasName);
                                        break;
                                    }
                                }
                                if ($artistFound)
                                    break;
                            } 
                            else
                            {
                                if (isset($alias['locale']) && $alias->attributes()->locale == 'ja')
                                    continue;
                                if (preg_match('/\b' . $this->__normalizeString($alias) . '\b/i', $stringToMatch) === 0)
                                {
                                    if (MusicBrainz::DEBUG_MODE)
                                        echo "Alias did not match: " . $alias . " (weight = $weight)\n";
                                    continue;
                                } 
                                else
                                {
                                    if (MusicBrainz::DEBUG_MODE)
                                        echo "Alias matched: " . $alias . " (weight = $weight)\n";
                                    $artistFound = $this->__normalizeString($alias);
                                    break;
                                }
                            }
                            if ($artistFound)
                                break;
                        }
                    }
                    if ($artistFound)
                        break;
                }
                if ($artistFound)
                    break;
            } 
            else
            {
                $artistFound = $this->__normalizeString($relArtist->name);
                break;
            }
        }

        if ($artistFound)
        {

            $artist = new mbArtist($relArtist->attributes()->id);
            $artist->setName($relArtist->name);
            $artist->setMatchString($artistFound);
            similar_text($queryText, $this->__normalizeString($relArtist->name), $percentMatch);
            $artist->setPercentMatch($percentMatch + $weight);

            if ($artist->getPercentMatch() > 15)
            {
                if(MusicBrainz::DEBUG_MODE)
                    echo "Artist name matched: " . $artist->getName() . " (percentMatch = " . $artist->getPercentMatch() . ")\n";
                return $artist;
            }
            elseif ($artistFound && $artist->getPercentMatch() > 0 && $artist->getPercentMatch() <= 15)
            {
                if(MusicBrainz::DEBUG_MODE)
                    echo "Artist percent match not acceptable: " . $artist->getPercentMatch() . "\n";
                return false;
            }
        }

        return false;
    }

    /**
     * @param $url
     *
     * @return bool|SimpleXMLElement
     * @throws MBException              exception thrown if php-curl not loaded, or
     *                                  if url contains musicbrainz.org and no valid
     *                                  email address is configured in site settings.
     *
     * NOTE: All requests to musicbrainz are in compliance with the MusicBrainz terms
     * of service, provided that the code below has not been altered from the author's
     * original work.  For the current release version of nZEDbetter, please visit
     * https://github.com/KurzonDax/nZEDbetter
     */
    protected  function __getResponse($url)
    {

        if (extension_loaded('curl'))
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->_applicationName . "/" . $this->_applicationVersion . "( " . $this->_email . " )");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            if ($this->_throttleRequests)
            {
                if (is_null($this->_email) || empty($this->_email) ||
                    preg_match('/[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:[A-Z]{2}|com|org|net|gov|mil|biz|info|mobi|name|aero|jobs|museum)\b/i', $this->_email) === 0)
                {
                    echo "\n\033[01;31mALERT!!! You have not set a valid email address in Admin->Site Settings.\n";
                    echo "The MusicBrainz integration will not function until this is corrected.\n\n";
                    throw new MBException("Invalid email address.  Halting MusicBrainz Processing.");
                }
                else //The following is REQUIRED if using musicbrainz.org for the server, per http://musicbrainz.org/doc/XML_Web_Service/Rate_Limiting
                    usleep($this->_threads * 9000);
            }

            $xml_response = curl_exec($ch);
            if ($xml_response === false)
            {
                curl_close($ch);

                return false;
            } else
            {
                /* parse XML */
                $parsed_xml = @simplexml_load_string($xml_response);
                curl_close($ch);

                return ($parsed_xml === false) ? false : $parsed_xml;
            }
        }
        else
        {
            throw new MBException('CURL-extension not loaded');
        }
    }

    /**
     * @param mbRelease $release    passed by reference, updated with path to saved cover art
     */
    private function __getCoverArt(mbRelease &$release)
    {
        $releaseImage = new ReleaseImage();
        if ($release->getCover() == true)
        {
            $imageName = "mb-" . $release->getMbID() . "-cover";
            $imageUrl = MusicBrainz::COVER_ART_BASE_URL . $release->getMbID() . "/front";
            $imageSave = $releaseImage->saveImage($imageName, $imageUrl, $this->_imageSavePath);
            $release->setCover(($imageSave ? $imageName . ".jpg" : 'NULL'));
        }
        elseif ($release->getAsin() != false && $this->_isAmazonValid)
        {
            // Get from Amazon if $release->asin != false and valid Amazon keys have been provided
            $amazon = new AmazonProductAPI($this->_amazonPublicKey, $this->_amazonPrivateKey, $this->_amazonTag);
            try
            {
                $amazonResults = $amazon->getItemByAsin($release->getAsin(), "com", "ItemAttributes,Images");
                if (isset($amazonResults->Items->Item->ImageSets->ImageSet->LargeImage->URL) && !empty($amazonResults->Items->Item->ImageSets->ImageSet->LargeImage->URL))
                {
                    $imageUrl = $amazonResults->Items->Item->ImageSets->ImageSet->LargeImage->URL;
                    $imageName = "mb-" . $release->getMbID() . "-cover";
                    $imageSave = $releaseImage->saveImage($imageName, $imageUrl, $this->_imageSavePath);
                    $release->setCover(($imageSave ? $imageName . ".jpg" : 'NULL'));
                }
            }
            catch (Exception $e)
            {
                $release->setCover('NULL');
            }
        }
        else
        {
            $release->setCover('NULL');
        }
    }

    /**
     * @param array             $query          Array {release => name of release we're looking for
     *                                                 year => year of release we're looking for or not set}
     * @param simpleXMLElement  $releaseList
     *
     * @return mbRelease|bool
     */
    private function __getRecordingRelease($query, $releaseList)
    {
        $releaseMatchFound = false;
        $x = 0;
        $releasePercentMatch = $tempReleasePercentMatch = -1000;
        $foundRelease = false;
        $mbRelease = new mbRelease();

        if (isset($query['release']))
        {
            $normalizedReleaseArr = array();
            $normalizedReleaseArr[] = $this->__normalizeString($query['release']);
            $normalizedReleaseArr[] = $this->__normalizeString($query['release'], true);

        }
        else
            $normalizedReleaseArr = null;

        foreach ($releaseList->release as $release)
        {
            if(MusicBrainz::DEBUG_MODE)
                echo "Check release:    " . $release->title . "\n";
            foreach ($normalizedReleaseArr as $normalizedRelease)
            {
                if (stripos($normalizedRelease, $this->__normalizeString($release->title)) === false &&
                    stripos($normalizedRelease, $this->__normalizeString($release->title, true)) === false &&
                    stripos($this->__normalizeString($release->title), $normalizedRelease) === false &&
                    stripos($this->__normalizeString($release->title, true), $normalizedRelease) === false &&
                    $normalizedRelease != $this->__normalizeString($release->title))
                    continue;
                else
                {
                    $releaseMatchFound = true;
                    break;
                }
            }

            if ($releaseMatchFound && isset($query['year']) && (isset($release->date) || isset($release->{'release-event-list'}->{'release-event'}->date)))
            {
                if(MusicBrainz::DEBUG_MODE)
                    echo "Checking year of release: " . $query['year'] . "\n";
                preg_match('/(19\d\d|20\d\d)/', (isset($release->date) ? $release->date : $release->{'release-event-list'}->{'release-event'}->date), $releaseYear);
                if (isset($releaseYear[0]))
                {
                    if ($query['year'] >= $releaseYear[0] - 1 && $query['year'] <= $releaseYear[0] + 1)
                        $releaseMatchFound = true;
                    else
                        $releaseMatchFound = false; // Reject match if the year isn't within + or - 1 year
                }
            }

            if ($releaseMatchFound)
            {
                if(MusicBrainz::DEBUG_MODE)
                    echo "Release match found: " . $release->title . "\n";
                similar_text($query['title'], $release->title, $tempReleasePercentMatch);
                $tempReleasePercentMatch += (((30 - $x) / 30) * 10); // matches weighted based on position in results list

                if ($tempReleasePercentMatch > $releasePercentMatch)
                {
                    $mbRelease->setMbID($release->attributes()->id);
                    $mbRelease->setTitle($release->title);
                    $mbRelease->setPercentMatch($tempReleasePercentMatch);
                    $releasePercentMatch = $tempReleasePercentMatch;
                    $foundRelease = true;
                }
            }
            // Increment the release result counter
            $x++;
        }

        return $foundRelease === true ? $mbRelease : false;
    }

    /**
     * @param string     $mbID          mbID for release to lookup
     * @param string     $mbArtistID    artist's mbID associated with release
     * @param int|float  $percentMatch
     *
     * @return bool|mbRelease
     */
    private function __getReleaseDetails ($mbID, $mbArtistID, $percentMatch = null)
    {

        $releaseInfo = $this->musicBrainzLookup('release', $mbID);

        if($releaseInfo)
        {
            $mbRelease = new mbRelease($mbID);
            if(!is_null($mbArtistID) && !empty($mbArtistID))
                $mbRelease->setArtistID($mbArtistID);
            elseif(isset($releaseInfo->release->{'artist-credit'}->{'name-credit'}->artist->attributes()->id))
                $mbRelease->setArtistID($releaseInfo->release->{'artist-credit'}->{'name-credit'}->artist->attributes()->id);
            else // Bail out because we can't set a valid artist ID
            {
                unset($mbRelease);
                return false;
            }
            $mbRelease->setTitle($releaseInfo->release->title);
            $mbRelease->setStatus($releaseInfo->release->status);

            if(isset($releaseInfo->release->date))
                $mbRelease->setReleaseDate($releaseInfo->release->date);
            elseif(isset($releaseInfo->release->{'release-event-list'}->{'release-event'}->date))
                $mbRelease->setReleaseDate($releaseInfo->release->{'release-event-list'}->{'release-event'}->date);

            if(isset($releaseInfo->release->{'release-group'}))
            {
                $mbRelease->setReleaseGroupID($releaseInfo->release->{'release-group'}->attributes()->id);
                if(isset($releaseInfo->release->{'release-group'}->{'tag-list'}))
                {
                    foreach($releaseInfo->release->{'release-group'}->{'tag-list'}->tag as $tag)
                        $mbRelease->addTag($tag->name);
                }
                if(isset($releaseInfo->release->{'release-group'}->rating))
                    $mbRelease->setRating($releaseInfo->release->{'release-group'}->rating);
            }
            if(isset($releaseInfo->release->country))
                $mbRelease->setCountry($releaseInfo->release->country);
            if(isset($releaseInfo->release->asin))
                $mbRelease->setAsin($releaseInfo->release->asin);
            if(isset($releaseInfo->release->{'medium-list'}->medium->{'track-list'}->attributes()->count))
                $mbRelease->setTracks($releaseInfo->release->{'medium-list'}->medium->{'track-list'}->attributes()->count);
            if(isset($releaseInfo->release->{'cover-art-archive'}->front))
                $mbRelease->setCover($releaseInfo->release->{'cover-art-archive'}->front == 'true' ? true : false);
            if(!is_null($percentMatch))
                $mbRelease->setPercentMatch($percentMatch);
        }
        else
            $mbRelease = false;

        return $mbRelease;
    }

    /**
     * @param string         $mbID           mbID of recording to lookup
     * @param string         $mbArtistID     artist's mbID associated with recording
     * @param string|null    $mbReleaseID    release's mbID associated with recording
     * @param float|int|null $percentMatch
     *
     * @return bool|mbTrack
     */
    private function __getRecordingDetails($mbID, $mbArtistID, $mbReleaseID=null, $percentMatch = null)
    {
        $recordingInfo = $this->musicBrainzLookup('recording', $mbID);

        if($recordingInfo)
        {
            $mbRecording = new mbTrack($mbID);
            $mbRecording->setTitle($recordingInfo->recording->title);
            if(!is_null($mbArtistID) && !empty($mbArtistID))
                $mbRecording->setArtistID($mbArtistID);
            else // Bail out because no artist ID was supplied
            {
                unset($mbRecording);
                return false;
            }
            if(!is_null($mbReleaseID) && !empty($mbReleaseID))
            {
                $mbRecording->setAlbumID($mbReleaseID);
                if(isset($recordingInfo->recording->{'release-list'}))
                {
                    foreach($recordingInfo->recording->{'release-list'}->release as $release)
                    {
                        if($release->attributes()->id == $mbReleaseID)
                        {
                            if(isset($release->date))
                            {
                                preg_match('/19\d\d|20\d\d/', $release->date, $year);
                                if(isset($year[0]))
                                    $mbRecording->setYear($year[0]);
                            }
                            elseif(isset($release->{'release-event-list'}->{'release-event'}->date))
                            {
                                preg_match('/19\d\d|20\d\d/', $release->date, $year);
                                if (isset($year[0]))
                                    $mbRecording->setYear($year[0]);
                            }
                            if(isset($release->{'medium-list'}))
                            {
                                $mbRecording->setTrackNumber($release->{'medium-list'}->medium->{'track-list'}->track->number);
                                $mbRecording->setDiscNumber($release->{'medium-list'}->medium->position);
                            }
                            break;
                        }
                    }
                }
            }
            if(isset($recordingInfo->recording->length))
                $mbRecording->setLength($recordingInfo->recording->length);
            if(!is_null($percentMatch))
                $mbRecording->setPercentMatch($percentMatch);
        }
        else
            $mbRecording = false;

        return $mbRecording;
    }

    /**
     * @param string  $mbID             mbID of artist to lookup
     * @param null    $percentMatch
     *
     * @return bool|mbArtist
     */
    private function __getArtistDetails($mbID, $percentMatch = null)
    {

        $artistInfo = $this->musicBrainzLookup('artist', $mbID);

        if($artistInfo)
        {
            $mbArtist = new mbArtist($mbID);
            $mbArtist->setType($artistInfo->artist->attributes()->type);
            $mbArtist->setName($artistInfo->artist->name);
            if(isset($artistInfo->artist->disambiguation))
                $mbArtist->setDisambiguation($artistInfo->artist->disambiguation);
            if($mbArtist->getType() == 'Person')
                $mbArtist->setGender($artistInfo->artist->gender);
            if(isset($artistInfo->artist->country))
                $mbArtist->setCountry($artistInfo->artist->country);
            if(isset($artistInfo->artist->lifespan->begin))
                $mbArtist->setBeginDate($artistInfo->artist->lifespan->begin);
            if(isset($artistInfo->artist->lifespan->end))
                $mbArtist->setEndDate($artistInfo->artist->lifespan->end);
            if(isset($artistInfo->artist->{'tag-list'}))
            {
                foreach($artistInfo->artist->{'tag-list'}->tag as $tag)
                {
                    $mbArtist->addTag($tag->name);
                }
            }
            if(isset($artistInfo->artist->rating))
                $mbArtist->setRating($artistInfo->artist->rating);
            if (!is_null($percentMatch))
                $mbArtist->setPercentMatch($percentMatch);
        }
        else
            $mbArtist = false;

        return $mbArtist;
    }
}

/**
 * Class MBException
 */
class MBException extends Exception{}
