<?php
	/**
	 * The logic behind this is pretty straightforward.
	 * 
	 * - find all the shows that are listed on the site
	 * - parse the list to build an array of shows, grouped
	 *   by category, title, and season
	 * - download everything one by one
	 * 
	 * Files that exist won't be re-downloaded
	 * 
	 * Requires rtmpdump in the same folder as this script.
	 */	
	
	$downloadFolder = 'D:/HGTV/';
	
	echo PHP_EOL;
	
	$showListUrl = 'http://feeds.theplatform.com/ps/JSON/PortalService/2.2/getCategoryList?callback=&field=ID&field=depth&field=hasReleases&field=fullTitle&PID=HmHUZlCuIXO_ymAAPiwCpTCNZ3iIF1EG&query=CustomText|PlayerTag|z/HGTVNEWVC%20-%20New%20Video%20Center&field=title&field=fullTitle&customField=TileAd&customField=DisplayTitle';
	
	echo 'HGTV Show Downloader v0.1'.PHP_EOL.PHP_EOL;
	echo '> Loading show list...';
	
	$shows = array();
	$response = file_get_contents($showListUrl);
	
	echo ' Parsing...';
	
	$json = json_decode($response);
	foreach ($json->items as $show) {
		if ($show->depth > 2) {
			unset($show->customData);
			$parts = explode('/', $show->fullTitle);
			if (count($parts) < 4) {
				print_r($show);
				throw new Exception('show title didnt have at least 4 parts!');
			}
			$show->category = $parts[1];
			$show->show_title = $parts[2];
			$show->season = $parts[3];
			$shows[$show->category][$show->show_title][$show->title][] = $show;
		}
	}
	
	echo ' Done!'.PHP_EOL.PHP_EOL;
	echo '== Lets start downloading some shows :) =='.PHP_EOL;
	
	if (!is_dir($downloadFolder)) {
		mkdir($downloadFolder);
	}
	
	foreach ($shows as $category => $serieses) {
		if (!is_dir($downloadFolder.$category)) {
			mkdir($downloadFolder.$category);
		}
		foreach ($serieses as $series => $seasons) {
			if (!is_dir($downloadFolder.$category.'/'.$series)) {
				mkdir($downloadFolder.$category.'/'.$series);
			}
			foreach ($seasons as $season => $data) {
				if (!is_dir($downloadFolder.$category.'/'.$series.'/'.$season)) {
					mkdir($downloadFolder.$category.'/'.$series.'/'.$season);
				}
				
				echo PHP_EOL.'Finding episodes for "' . $series . ' - ' . $season . '"...';
				$episodes = getEpisodes($data[0]->ID);
				echo ' Found ' . count($episodes) . ' episode(s)'.PHP_EOL;
				
				if (count($episodes) > 0) {
					foreach ($episodes as $episode) {
						if (file_exists($downloadFolder.$category.'/'.$series.'/'.$season.'/'.$episode['title'].'.flv')) {
							echo '> "'.$episode['title'].'" exists, skipping.'.PHP_EOL;
						}
						else {
							echo '> Downloading "'.$episode['title'].'"... ';
							exec('rtmpdump -r "' . $episode['stream'] . '" -y "' . $episode['playlist'] . '" -o "' . $downloadFolder.$category.'/'.$series.'/'.$season.'/'.$episode['title'].'.flv"');
							echo PHP_EOL;
						}
					}
				}
			}
		}
	}
	
	/**
	 * Finds all the episodes for a given season. Also requests and parses
	 * playlist XML file, and finds RTMP stream URL
	 * 
	 * @param <int> $id The ID of the season to find episodes for
	 * @return <array> An array of episodes, containing 'title', 'stream' and 'playlist' keys
	 * @throws Exception If RTMP URL cant be found, or if RTMP URL is missing <break>
	 */
	function getEpisodes($id) {
		// 99 episodes at a time (start & end index)
		$url = 'http://feeds.theplatform.com/ps/JSON/PortalService/2.2/getReleaseList?callback=&field=ID&field=contentID&field=PID&field=URL&field=categoryIDs&field=length&field=airdate&field=requestCount&PID=HmHUZlCuIXO_ymAAPiwCpTCNZ3iIF1EG&contentCustomField=Show&contentCustomField=Episode&contentCustomField=Network&contentCustomField=Season&contentCustomField=Zone&contentCustomField=Subject&query=Categories|z/HGTVNEWVC%20-%20New%20Video%20Center&param=Site|shaw.hgtv.ca&param=k0|id&param=v0|399&param=k1|cnt&param=v1|lifestylehomes&param=k2|nk&param=v2|sbrdcst&param=k3|pr&param=v3|hgtv&param=k4|kw&param=v4|shaw&param=k5|test&param=v5|test&param=k6|ck&param=v6|video&param=k7|imp&param=v7|video&param=k8|liveinsite&param=v8|hrtbuy&query=CategoryIDs|'.$id.'&field=thumbnailURL&field=title&field=length&field=description&field=assets&contentCustomField=Part&contentCustomField=Clip%20Type&contentCustomField=Web%20Exclusive&contentCustomField=ChapterStartTimes&contentCustomField=AlternateHeading&startIndex=1&endIndex=99&sortField=airdate&sortDescending=true';
		$episodes = array();
		$response = file_get_contents($url);
		$json = json_decode($response);
		foreach ($json->items as $episode) {
			$show = null;
			$ep = null;
			$season = null;
			foreach ($episode->contentCustomData as $data) {
				switch ($data->title) {
					case 'Show':
						$show = $data->value;
						break;
					case 'Episode':
						$ep = $data->value;
						break;
					case 'Season':
						$season = $data->value;
						break;
				}
			}
			if (!$show || !$ep || !$season) {
				continue;
			}
			$title = $show . ' - S' . $season . 'E' . $ep . ' - ' . $episode->title;
			$xmlString = file_get_contents($episode->URL);
			$xml = simplexml_load_string($xmlString);
			$streamUrl = null;
			foreach ($xml->choice as $choice) {
				if (strpos($choice->url, 'rtmp://') !== false) {
					$streamUrl = $choice->url;
				}
			}
			if (!$streamUrl) {
				throw new Exception('Failed to find rtmp stream for ' . $id);
			}
			$streamParts = explode('<break>', $streamUrl);
			if (count($streamParts) != 2) {
				throw new Exception('stream url didnt explode on <break>. url was: ' . $streamUrl);
			}
			$stream = $streamParts[0];
			$playlist = 'mp4:'.$streamParts[1];
			$episodes[] = array(
			    'title' => $title,
			    'stream' => $stream,
			    'playlist' => $playlist
			);
		}
		return $episodes;
	}
	
?>