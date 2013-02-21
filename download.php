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
	 * Shows found in $finishedShows won't have their episodes fetched.
	 * >> NOTE: $finishedShows file must always end in \n (unless empty of course)
	 * 
	 * Requires rtmpdump in the same folder as this script.
	 */	
	
	$downloadFolder = 'D:/HGTV/';
	$timesToRetry = 5;
	$sleep = 30; // sleep for 30s between retries
	$finishedShows = __DIR__ . '/finishedShows.txt'; // ignore these shows
	$finishedSeasons = __DIR__ . '/finishedSeasons.txt'; // ignore these seasons
	
	echo PHP_EOL;
	echo 'HGTV Show Downloader v0.2'.PHP_EOL.PHP_EOL;
	
	$ignoreShows = array();
	if ($fp = fopen($finishedShows, 'a+')) {
		while ($show = trim(fgets($fp))) {
			if (strpos($show, '#') !== 0) {
				$ignoreShows[] = $show;
			}
		}
		fclose($fp);
	}
	else {
		echo '>> Note: "Finished Shows" file (' . $finishedShows . ') couldn\'t be read. <<'.PHP_EOL.PHP_EOL;
	}
	
	$ignoreSeasons = array();
	if ($fp = fopen($finishedSeasons, 'a+')) {
		while ($season = trim(fgets($fp))) {
			if (strpos($season, '#') !== 0) {
				$ignoreSeasons[] = $season;
			}
		}
		fclose($fp);
	}
	else {
		echo '>> Note: "Finished Seasons" file (' . $finishedSeasons . ') couldn\'t be read. <<'.PHP_EOL.PHP_EOL;
	}
	
	$showListUrl = 'http://feeds.theplatform.com/ps/JSON/PortalService/2.2/getCategoryList?callback=&field=ID&field=depth&field=hasReleases&field=fullTitle&PID=HmHUZlCuIXO_ymAAPiwCpTCNZ3iIF1EG&query=CustomText|PlayerTag|z/HGTVNEWVC%20-%20New%20Video%20Center&field=title&field=fullTitle&customField=TileAd&customField=DisplayTitle';
	
	echo '> Loading show list...';
	
	$shows = array();
	$response = file_get_contents($showListUrl);
	
	if ($response) {
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
				if (!in_array($series, $ignoreShows)) {
					if (!is_dir($downloadFolder.$category.'/'.$series)) {
						mkdir($downloadFolder.$category.'/'.$series);
					}
					foreach ($seasons as $season => $data) {
						$folder = $downloadFolder.$category.'/'.$series.'/'.$season.'/';
						if (!is_dir($folder)) {
							mkdir($folder);
						}

						echo PHP_EOL.'Finding episodes for "' . $series . ' - ' . $season . '"...';
						$episodes = getEpisodes($data[0]->ID);
						echo ' Found ' . count($episodes) . ' episode(s)'.PHP_EOL;

						if (count($episodes) > 0) {
							foreach ($episodes as $episode) {
								$fileName = str_replace(array(':', '?'), array(' -', ''), $episode['title']);
								if (file_exists($folder.$fileName.'.flv')) {
									echo '> "'.$episode['title'].'" exists, skipping.'.PHP_EOL;
								}
								else {
									$tries = 0;
									while(true) {
										echo '> Downloading "'.$episode['title'].'"... ';
										$code = null;
										$temp = array();
										exec('rtmpdump -r "' . $episode['stream'] . '" -y "' . $episode['playlist'] . '" -o "' . $folder.$fileName . '.flv"', $temp, $code);
										if ($code == 0) {	
											echo ' Download complete!'.PHP_EOL;
											break;
										}
										else {
											echo '> Download failed.';
											if ($tries < $timesToRetry) {
												echo ' Trying again in '.$sleep.'s'.PHP_EOL;
												$tries++;
												sleep($sleep);
											}
											else {
												echo 'Too many retries. Removing incomplete file...';
												if (file_exists($folder.$fileName.'.flv')) {
													unlink($folder.$fileName.'.flv'); 
												}
												echo ' Goodbye.';
												die;
											}
										}
									}
								}
							}
						}
						if ($fp = fopen($finishedSeasons, 'a')) {
							fwrite($fp, $series . ' - ' . $season."\n");
							fclose($fp);
						}
						else {
							echo '>> Failed saving to "Finished Seasons" file ('.$finishedSeasons.')'.PHP_EOL;
						}
					}
					if ($fp = fopen($finishedShows, 'a')) {
						fwrite($fp, $series."\n");
						fclose($fp);
					}
					else {
						echo '>> Failed saving to "Finished Shows" file ('.$finishedShows.')'.PHP_EOL;
					}
				}
				else {
					echo PHP_EOL . 'Ignoring "' . $series . '"'.PHP_EOL;
				}
			}
		}
	}
	else {
		throw new Exception('Show list response was empty');
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
		if ($response) {
			$json = json_decode($response);
			foreach ($json->items as $episode) {
				$show = null;
				$ep = null;
				$season = null;
				$alternateHeading = null;
				foreach ($episode->contentCustomData as $data) {
					switch ($data->title) {
						case 'Show':
							$show = $data->value;
							break;
						case 'Episode':
							if ($data->value != '') {
								$ep = str_pad($data->value, 2, '0', STR_PAD_LEFT);
							}
							break;
						case 'Season':
							if ($data->value != '') {
								$season = str_pad($data->value, 2, '0', STR_PAD_LEFT);
							}
							break;
						case 'AlternateHeading':
							$alternateHeading = $data->value;
							break;
					}
				}
				
				// ffs, some episodes dont have an episode #. parse it
				if (!$ep && !$alternateHeading && $episode->thumbnailURL && $show) {
					// HGTV_DeckedOut_E1013
					$matches = array();
					preg_match('/HGTV_'.str_replace(' ', '', $show).'_E[0-9]{1,2}([0-9][0-9])_/', $episode->thumbnailURL, $matches);
					
					if (isset($matches[1]) && is_numeric($matches[1])) { // decimal episode numbers?
						$ep = $matches[1]; // got it
					}
					else {
						var_dump($matches);
						var_dump($episode); die;
					}
				}
				
				// fail if either set is missing
				if ((!($show || $alternateHeading)) || (!($show || $season || $ep))) {
					var_dump($episode);
					throw new Exception('Missing one of show/ep/season');
				}
				
				// things like "outtakes" and "timelapses" are missing $ep but have an $alternateHeading
				if ($show && $alternateHeading) {
					$title = $show . ' - ' . $alternateHeading . ' - ' . $episode->title;
				}
				else {
					$title = $show . ' - S' . $season . 'E' . $ep . ' - ' . $episode->title;
				}
				$xmlString = file_get_contents($episode->URL);
				if ($xmlString) {
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
					$playlistFile = $streamParts[1];
					$playlistFileExtension = pathInfo($playlistFile, PATHINFO_EXTENSION);
					if ($playlistFileExtension == 'flv') {
						$playlist = 'flv:'.str_replace('.flv', '', $playlistFile);
					}
					elseif ($playlistFileExtension == 'mp4') {
						$playlist = 'mp4:'.$playlistFile;
					}
					else {
						throw new Exception('Unhandled playlist file extension: ' . $playlistFile);
					}

					$episodes[] = array(
					    'title' => $title,
					    'stream' => $stream,
					    'playlist' => $playlist
					);
				}
				else {
					throw new Exception('episode->url response was empty');
				}
			}
			return $episodes;
		}
		else {
			throw new Exception('Episode list response was empty');
		}
	}
	
?>