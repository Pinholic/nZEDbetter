<?php
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/groups.php");
require_once(WWW_DIR."/lib/nntp.php");
require_once(WWW_DIR."/lib/binaries.php");

// define('LOG_DIR', "/var/www/nZEDb/www/lib/logging/");
// define('LOG_ACTIVE', TRUE);

class Backfill
{
	
	
	function Backfill()
	{
		$this->n = "\n";
		$s = new Sites();
		$site = $s->get();
		$this->safebdate = (!empty($site->safebackfilldate)) ? $site->safebackfilldate : 2012-06-24;
		$this->hashcheck = (!empty($site->hashcheck)) ? $site->hashcheck : 0;
		$this->sleeptime = (!empty($site->postdelay)) ? $site->postdelay : 300;
        $this->switchToPosts = (!empty($site->switchToPosts)) ? $site->switchToPosts : 0;
        $this->useCompression = (!empty($site->compressedheaders)) ? $site->compressedheaders : 0;
	}

	//
	// Update all active groups categories and descriptions
	//
	function backfillAllGroups($groupName='')
	{
		if ($this->hashcheck == 0)
			exit("You must run update_binaries.php to update your collectionhash.\n");
		$n = $this->n;
		$groups = new Groups();
        $compressedFailed = false;

		if ($groupName != '')
		{
			$grp = $groups->getByName($groupName);
			if ($grp)
			{
				$res = array($grp);
			}
		}
		else
		{
			$res = $groups->getActiveBackfill();
		}

		$counter = 1;
		if (@$res)
		{
			// TODO: Why are we connecting to the NNTP server twice?
			// With compression.
			    $nntpc = new Nntp();
                $nntpc->doConnect();
            // Without compression
                $nntp = new Nntp();
                $nntp->doConnectNC();

			foreach($res as $groupArr)
			{
				$left = sizeof($res)-$counter;
				echo $n."Starting group ".$counter." of ".sizeof($res).".".$n;
				$this->backfillGroup($nntp, $nntpc, $groupArr, $left);
				$counter++;
			}
			$nntp->doQuit();
			$nntpc->doQuit();
		}
		else
		{
			echo "No groups specified. Ensure groups are added to nZEDb's database for updating.".$n;
		}
	}

	function backfillGroup($nntp, $nntpc, $groupArr, $left)
	{
		$db = new DB();
		$binaries = new Binaries();
		$n = $this->n;
		$this->startGroup = microtime(true);
        // TODO: Again, why are we connecting to the NNTP server twice?
		// Compression.
		if (!isset($nntp))
		{
			$nntp = new Nntp;
			$nntp->doConnectNC();
		}
		if (!isset($nntpc))
		{
			$nntp = new Nntp;
			$nntp->doConnect();
		}
		// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "selectGroup:datac", "Attempting to select group");
		$datac = $nntpc->selectGroup($groupArr['name']);
		if (PEAR::isError($datac))
		{
			// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "selectGroup:datac", "Failed... Attempting reconnect");
			$nntpc->doQuit();
			unset($nntpc);
			$nntpc = new Nntp;
			$nntpc->doConnect();
			$datac = $nntpc->selectGroup($groupArr['name']);
			if (PEAR::isError($datac))
			{
				echo "Error {$datac->code}: {$datac->message}.".$n;
				echo "Reconnected but could not select group (bad name?): {$groupArr['name']}".$n;
				return;
			}
		}

		// No comp - for interval.
		// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "selectGroup:data (no comp)", "Attempting to select group");
		$data = $nntp->selectGroup($groupArr['name']);
		if (PEAR::isError($data))
		{
			// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "selectGroup:data (no comp)", "Failed... Attempting reconnect");
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnectNC();
			$data = $nntp->selectGroup($groupArr['name']);
			if (PEAR::isError($data))
			{
				echo "Error {$data->code}: {$data->message}.".$n;
				echo "Reconnected but could not select group (bad name?): {$groupArr['name']}".$n;
				return;
			}
		}

		// Get targetpost based on days target.
		$targetpost = $this->daytopost($nntp,$groupArr['name'],$groupArr['backfill_target'],TRUE);
		if ($targetpost < 0)
			$targetpost = round($data['first']);
		if($groupArr['first_record'] == 0 || $groupArr['backfill_target'] == 0)
		{
			echo "Group ".$groupArr['name']." has invalid numbers. Have you run update on it? Have you set the backfill days amount?".$n;
			return;
		}

		// Check if we are grabbing further than the server has.
        // TODO: Why the hardcoded value here?
		if($groupArr['first_record'] <= $data['first']+50000)
		{
			echo "We have hit the maximum we can backfill for this ".$groupArr['name'].", disabling it.".$n.$n;
			$groups = new Groups();
			$groups->disableForPost($groupArr['name']);
			return "";
		}
		// If our estimate comes back with stuff we already have, finish.
		if($targetpost >= $groupArr['first_record'])
		{
			echo "Nothing to do, we already have the target post".$n.$n;
			return "";
		}

		echo "Group ".$data["group"].": server has ".number_format($data['first'])." - ".number_format($data['last']).", or ~".
				((int) (($this->postdate($nntp,$data['last'],FALSE,$groupArr['name']) - $this->postdate($nntp,$data['first'],FALSE,$groupArr['name']))/86400)).
				" days.".$n."Local first = ".number_format($groupArr['first_record'])." (".
				((int) ((date('U') - $this->postdate($nntp,$groupArr['first_record'],FALSE,$groupArr['name']))/86400)).
				" days).  Backfill target of ".$groupArr['backfill_target']." days is post $targetpost".$n;

		// Calculate total number of parts.
		$total = $groupArr['first_record'] - $targetpost;
		$done = false;
		// Set first and last, moving the window by maxxMssgs.
		$last = $groupArr['first_record'] - 1;
		// Set the initial "chunk".
		$first = $last - $binaries->messagebuffer + 1;
		// Just in case this is the last chunk we needed.
		if($targetpost > $first)
		{
			$first = $targetpost;
		}
		while($done === false)
		{
			$binaries->startLoop = microtime(true);
            // Forcing the text color to white below so I can quickly spot errors
			echo "\033[00;37mGetting ".(number_format($last-$first+1))." articles from ".$data["group"].", ".$left." group(s) left. (".(number_format($first-$targetpost))." articles in queue).".$n;
			flush();
			// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "scan", "Starting binaries:scan");
			$binaries->scan($nntpc, $groupArr, $first, $last, 'backfill');
			// $this->logResponse("Backfill:backfillGroup", $groupArr['name'], "scan", "Finished binaries:scan")

			$db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$this->postdate($nntp,$first,false,$groupArr['name'])."), first_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
			if($first==$targetpost)
				$done = true;
			else
			{
				//Keep going: set new last, new first, check for last chunk.
				$last = $first - 1;
				$first = $last - $binaries->messagebuffer + 1;
				if($targetpost > $first)
				{
					$first = $targetpost;
				}
			}
		}
		$first_record_postdate = $this->postdate($nntp,$first,false,$groupArr['name']);
		// Set group's first postdate.
		$db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$first_record_postdate."), last_updated = now() WHERE ID = %d", $groupArr['ID']));

		$timeGroup = number_format(microtime(true) - $this->startGroup, 2);
		echo "Group processed in ".$timeGroup." seconds.".$n;
		// Increment the backfil target date.
	}

	//
	// Safe backfill using posts.
	//
	function safeBackfill($articles='')
	{
		if ($this->hashcheck == 0)
			exit("You must run update_binaries.php to update your collectionhash.\n");
		$db = new DB();
		$n = $this->n;

		$groupname = $db->queryOneRow(sprintf("select name from groups WHERE (first_record_postdate BETWEEN %s and now()) and (backfill = 1) order by name asc", $db->escapeString($this->safebdate)));

		if (!$groupname)
		{
			exit("No groups to backfill, they are all at the target date ".$this->safebdate.".".$n);
		}
		else
		{
			$this->backfillPostAllGroups($groupname["name"], $articles);
		}
	}

	//
	// Update all active groups categories and descriptions using article numbers instead of date.
	//
	function backfillPostAllGroups($groupName='', $articles = '', $type='')
	{
		if ($this->hashcheck == 0)
			exit("You must run update_binaries.php to update your collectionhash.\n");
		$n = $this->n;
		$groups = new Groups();
		if ($groupName != '')
		{
			$grp = $groups->getByName($groupName);
			if ($grp)
			{
				$res = array($grp);
			}
		}
		else
		{
			if($type == "normal")
			{
				$res = $groups->getActiveBackfill();
			}
			else if($type == "date")
			{
				$res = $groups->getActiveByDateBackfill();
			}
		}

		$counter = 1;
		if (@$res)
		{
			// We do not use interval here, so use a compressed connection only - testing.
			foreach($res as $groupArr)
			{
				$left = sizeof($res)-$counter;
				echo $n."Starting group ".$counter." of ".sizeof($res).".".$n;
				$this->backfillPostGroup($groupArr, $articles, $left);
				$counter++;
			}
		}
		else
		{
			echo "No groups specified. Ensure groups are added to nZEDbetter's database for updating.".$n;
		}
	}

	function backfillPostGroup($groupArr, $articles = '', $left)
	{
		$db = new DB();
		$binaries = new Binaries();
        //TODO: Shouldn't we be checking whether to use compression here?
		$nntp = new Nntp();
		$nntp->doConnect();
		$n = $this->n;
		$this->startGroup = microtime(true);

		echo 'Processing '.$groupArr['name'].$n;
		$data = $nntp->selectGroup($groupArr['name']);

		if (PEAR::isError($data))
		{
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnect();
			$data = $nntp->selectGroup($groupArr['name']);
			if (PEAR::isError($data))
			{
				echo "Error {$data->code}: {$data->message}.".$n;
				echo "Reconnected but could not select group (bad name?): {$groupArr['name']}".$n;
				return;
			}
		}

		// Get targetpost based on days target.
		$targetpost =  round($groupArr['first_record']-$articles);
		if ($targetpost < 0)
			$targetpost = round($data['first']);
        // TODO: If $targetpost < 0, it may mean there are just fewer than $articles left to backfill.  We should get those articles
		if($groupArr['first_record'] <= 0 || $targetpost <= 0)
		{
			echo "You need to run update_binaries on the ".$data['group'].". Otherwise the group is dead, you must disable it.".$n;
			return "";
		}

		// Check if we are grabbing further than the server has.
        // TODO: This has to be a bug.  Should be $targetpost <= $data['first'] then just get remaining articles and disable group backfill
		if($groupArr['first_record'] <= $data['first']+$articles)
		{
			//echo $groupArr['first_record']."\n";
			//echo $data['first']."\n";
			echo "We have hit the maximum we can backfill for ".$data['group'].", disabling it.".$n.$n;
			$groups = new Groups();
			$groups->disableForPost($groupArr['name']);
			return "";
		}

		// If our estimate comes back with stuff we already have, finish.
		if($targetpost >= $groupArr['first_record'])
		{
			echo "Nothing to do, we already have the target post".$n.$n;
			return "";
		}

		echo "Group ".$data["group"]."'s oldest article is ".number_format($data['first']).", newest is ".number_format($data['last']).". The groups retention is: ".
				((int) (($this->postdate($nntp,$data['last'],FALSE,$groupArr['name']) - $this->postdate($nntp,$data['first'],FALSE,$groupArr['name']))/86400)).
				" days.".$n."Our oldest article is: ".number_format($groupArr['first_record'])." which is (".
				((int) ((date('U') - $this->postdate($nntp,$groupArr['first_record'],FALSE,$groupArr['name']))/86400)).
				" days old). Our backfill target is article ".number_format($targetpost)." which is (".((int) ((date('U') - $this->postdate($nntp,$targetpost,FALSE,$groupArr['name']))/86400)).$n.
				" days old).".$n;

		// Calculate total number of parts.
		$total = $groupArr['first_record'] - $targetpost;
		$done = false;
		// Set first and last, moving the window by maxxMssgs.
		$last = $groupArr['first_record'] - 1;
		// Set the initial "chunk".
		$first = $last - $binaries->messagebuffer + 1;
		// Just in case this is the last chunk we needed.
		if($targetpost > $first)
		{
			$first = $targetpost;
		}
		while($done === false)
		{
			$binaries->startLoop = microtime(true);

			echo $n."Getting ".($last-$first+1)." articles from ".$data["group"].", ".$left." group(s) left. (".($first-$targetpost)." articles in queue).".$n;
			flush();
			$binaries->scan($nntp, $groupArr, $first, $last, 'backfill');

			$db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$this->postdate($nntp,$first,false,$groupArr['name'])."), first_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
			if($first==$targetpost)
				$done = true;
			else
			{
				//Keep going: set new last, new first, check for last chunk.
				$last = $first - 1;
				$first = $last - $binaries->messagebuffer + 1;
				if($targetpost > $first)
				{
					$first = $targetpost;
				}
			}
		}
		$first_record_postdate = $this->postdate($nntp,$first,false,$groupArr['name']);
		$nntp->doQuit();
		// Set group's first postdate.
		$db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$first_record_postdate."), last_updated = now() WHERE ID = %d", $groupArr['ID']));

		$timeGroup = number_format(microtime(true) - $this->startGroup, 2);
		echo $data["group"]." processed in ".$timeGroup." seconds.".$n;
		// Increment the backfil target date.
	}

	//
	// Returns a single timestamp from a local article number.
	//
	function postdate($nntp,$post,$debug=true,$group)
	{
		$n = $this->n;
		if (!isset($nntp))
		{
			$nntp = new Nntp;
			$nntp->doConnect();
		}

		$attempts=0;
		do
		{
			$data = $nntp->selectGroup($group);
			if(PEAR::isError($data))
			{
				$nntp->doQuit();
				unset($nntp);
				$nntp = new Nntp;
				$nntp->doConnect();
				$data = $nntp->selectGroup($group);
				if(PEAR::isError($data))
				{
					echo "Error {$data->code}: {$data->message}.".$n;
					return;
				}
			}
			$msgs = $nntp->getOverview($post."-".$post,true,true);
			if(PEAR::isError($msgs))
			{
				$nntp->doQuit();
				unset($nntp);
				$nntp = new Nntp;
				$nntp->doConnect();
				$data = $nntp->selectGroup($group);
				$msgs = $nntp->getOverview($post."-".$post,true,false);
				if(PEAR::isError($msgs))
				{
					echo "Error {$msgs->code}: {$msgs->message}.".$n."Returning from postdate. Group: ".$group."  Post: ".$post.$n;
					return false;
				}
			}

			if(!isset($msgs[0]['Date']) || $msgs[0]['Date']=="" || is_null($msgs[0]['Date']))
			{
				$success=false;
			}
			else
			{
				$date = $msgs[0]['Date'];
				$success=true;
			}
			if($debug && $attempts > 0) echo "Retried ".$attempts." time(s).".$n;
			usleep(100000);
			$attempts++;
		}while($attempts <= 5 && $success == false);

		if (!$success)
		{
			echo $n."Error: $group failed to get Date from NNTP server.".$n;
			//use now - 365 days
			$date = TIME() - 31536000;
			return $date;
		}

		if($debug) echo "DEBUG: postdate for post: .".$post." came back ".$date." (";
		$date = strtotime($date);

		if($debug) echo $date." seconds unixtime or ".$this->daysOld($date)." days)".$n;
		return $date;
	}

	function daytopost($nntp, $group, $days, $debug=true)
	{
		$n = $this->n;
		// DEBUG every postdate call?!?!
		$pddebug = false;
		if ($debug)
		{
			echo "INFO: Finding article for ".$group." ".$days." days back.".$n;
		}

		if (!isset($nntp))
		{
			$nntp = new Nntp;
			$nntp->doConnect();
		}

		$data = $nntp->selectGroup($group);
		if(PEAR::isError($data))
		{
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnect();
			$data = $nntp->selectGroup($group);
			if(PEAR::isError($data))
			{
				echo "Error {$data->code}: {$data->message}".$n."Returning from daytopost.".$n;
				return;
			}
		}

		// Goal timestamp.
		$goaldate = date('U')-(86400*$days);
		$totalnumberofarticles = $data['last'] - $data['first'];
		$upperbound = $data['last'];
		$lowerbound = $data['first'];
		if ($debug)
		{
			echo "Total Articles: ".number_format($totalnumberofarticles)." Newest: ".number_format($upperbound)." Oldest: ".number_format($lowerbound).$n."Goal: ".date("r", $goaldate)." ($goaldate).".$n;
		}
		if ($data['last'] == PHP_INT_MAX)
		{
			die("ERROR: Group data is coming back as php's max value. You should not see this since we use a patched Net_NNTP that fixes this bug.".$n);
		}
		$firstDate = $this->postdate($nntp, $data['first'], $pddebug, $group);
		$lastDate = $this->postdate($nntp, $data['last'], $pddebug, $group);
		if ($goaldate < $firstDate)
		{
			echo "WARNING: Backfill target of $days day(s) is older than the first article stored on your news server.".$n.
				 "Starting from the first available article (".date("r", $firstDate)." or ".$this->daysOld($firstDate)." days).".$n;
			return $data['first'];
		}
		elseif ($goaldate > $lastDate)
		{
            if($this->switchToPosts==0)
            {
                echo "ERROR: Backfill target of ".$days." day(s) is newer than the last article stored on your news server.".$n.
                    "To backfill this group you need to set Backfill Days to at least ".ceil($this->daysOld($lastDate)+1)." days (".date("r", $lastDate-86400).").".$n;
                return "";
            }
            elseif($this->switchToPosts==1)
            {
                echo "ERROR: Backfill target of ".$days." day(s) is newer than the last article stored on your news server.".$n;
                // Newsgroup may not be updated very often, so we will try to retrieve based on last X number of posts
                return "-1";
            }
		}
		if ($debug)
		{
			echo "DEBUG: Searching for postdate.".$n."Goaldate: ".$goaldate." (".date("r", $goaldate).") ".$n." Firstdate: ".$firstDate." (".((is_int($firstDate))?date("r", $firstDate):'n/a').") ".$n.". Lastdate: ".$lastDate." (".date("r", $lastDate).").".$n;
		}

		$interval = floor(($upperbound - $lowerbound) * 0.5);
		$dateofnextone = "";
		$templowered = "";

		if ($debug)
		{
			echo "Start: ".$data['first'].$n."End: ".$data['last'].$n."Interval: ".$interval.$n;
		}

		$dateofnextone = $lastDate;
		// Match on days not timestamp to speed things up.
		while($this->daysOld($dateofnextone) < $days)
		{
			while(($tmpDate = $this->postdate($nntp,($upperbound-$interval),$pddebug,$group))>$goaldate)
			{
				$upperbound = $upperbound - $interval;
				if($debug) echo "New upperbound (".$upperbound.") is ".$this->daysOld($tmpDate)." days old.".$n;
			}
			if(!$templowered)
			{
				$interval = ceil(($interval/2));
				if($debug) echo "Set interval to ".$interval." articles.".$n;
		 	}
		 	$attempts = 0;
            $dateofnextone = $this->postdate($nntp,($upperbound-1),$pddebug,$group);
			while(!$dateofnextone && $attempts < 10)
			{
                $attempts++;
                $dateofnextone = $this->postdate($nntp,($upperbound-1),$pddebug,$group); }
	 	    }
        if($attempts >= 10)
        {
            echo "\033[01;31mUnable to locate a valid article for group ".$group."\n\033[00;37m";
            echo "Switching to posts mode for this group...".$n;
            if ($this->switchToPosts == 1) return "-1";
            else return "";
        }
		echo "Determined to be article $upperbound which is ".$this->daysOld($dateofnextone)." days old (".date("r", $dateofnextone).").".$n;
		return $upperbound;
	}

	private function daysOld($timestamp)
	{
		return round((time()-$timestamp)/86400, 1);
	}

	function getRange($group, $first, $last, $threads)
	{
		$db = new DB();
		$n = $this->n;
		$groups = new Groups();
		$this->startGroup = microtime(true);
		$site = new Sites();
		$backthread = $site->get()->backfillthreads;
		$binaries = new Binaries();
		$groupArr = $groups->getByName($group);

		echo 'Processing '.$groupArr['name']." ==> T-".$threads." ==> ".number_format($first)." to ".number_format($last).$n;
		$this->startLoop = microtime(true);
		//let scan handle the connection
		$lastId = $binaries->scan(null, $groupArr, $last, $first, 'backfill');
		if ($lastId === false)
		{
			//scan failed - skip group
			return;
		}
	}

	function getFinal($group, $first)
	{
		$db = new DB();
		$groups = new Groups();
		$groupArr = $groups->getByName($group);
		//let postdate handle the connection
		$db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$this->postdate(null,$first,false,$group)."), first_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
		echo "Backfill Safe Threaded on ".$group." completed.\n\n";
	}
	
	// function logResponse($func, $grp, $cmd, $resp)
	// {
		// if (LOG_ACTIVE===true)
		// {
			// $msg = time()." Function: ".$func."  Group: ".$grp." Command: ".$cmd." Response: ".$resp."\n";
			// if ($grp===NULL)
			//	$grp="default";
			
			// if ($grp==="") 
			//	$grp="default";
			// file_put_contents(LOG_DIR.$grp, $msg, FILE_APPEND);
			// echo $msg;
			
	//	}
			
	// }
}
