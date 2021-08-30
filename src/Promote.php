<?php

class Promote {
	function __construct(EchoHelper $eh, StringHelper $sh) {
		$this->eh = $eh;
		$this->sh = $sh;
	}
	
	function sliceNovemBotPromoteTemplate($wikicode, $title) {
		preg_match('/\{\{User:NovemBot\/Promote([^\}]*)\}\}/i', $wikicode, $matches);
		if ( ! $matches ) {
			throw new GiveUpOnThisTopic("On page $title, unable to find {{User:NovemBot/Promote}} template.");
		}
		$templateWikicode = $matches[1];
		//$this->eh->echoAndFlush($templateWikicode, 'variable');
		return $templateWikicode;
	}

	function abortIfAddToTopic($callerPageWikicode, $title) {
		preg_match('/\{\{Add to topic/i', $callerPageWikicode, $matches);
		if ( $matches ) {
			throw new GiveUpOnThisTopic("On page $title, {{Add to topic}} is present. Bot does not know how to handle these.");
		}
	}

	function getGoodOrFeaturedFromNovemBotTemplate($novemBotTemplateWikicode, $title) {
		preg_match('/\|type=([^\|\}]*)/', $novemBotTemplateWikicode, $matches);
		if ( ! $matches ) {
			throw new GiveUpOnThisTopic("On page $title, unable to find |type= parameter of {{User:NovemBot/Promote}}.");
		}
		$type = $matches[1];
		if ( $type != 'good' && $type != 'featured' ) {
			throw new GiveUpOnThisTopic("On page $title, |type= parameter of {{User:NovemBot/Promote}} must be \"good\" or \"featured\|.");
		}
		//$this->eh->echoAndFlush($type, 'variable');
		return $type;
	}

	function getTopicBoxWikicode($callerPageWikicode, $title) {
		$wikicode = $this->sh->sliceFirstTemplateFound($callerPageWikicode, 'good topic box');
		if ( $wikicode ) {
			return $wikicode;
		}
		$wikicode = $this->sh->sliceFirstTemplateFound($callerPageWikicode, 'featured topic box');
		if ( $wikicode ) {
			return $wikicode;
		}
		throw new GiveUpOnThisTopic("On page $title, {{Good/featured topic box}} not found.");
	}

	function getMainArticleTitle($topicBoxWikicode, $title) {
		// TODO: are there any other possible icons besides FA, GA, FL?
		// TODO: handle piped links
		preg_match("/\|\s*lead\s*=\s*{{\s*(?:class)?icon\s*\|\s*(?:FA|GA|FL)\s*}}\s*(?:'')?\[\[([^\]\|]*)/i", $topicBoxWikicode, $matches);
		if ( ! $matches ) {
			throw new GiveUpOnThisTopic("On page $title, could not find main article name in {{Good/Featured topic box}}.");
		}
		$mainArticleTitle = $matches[1];
		//$this->eh->echoAndFlush($mainArticleTitle, 'variable');
		return $mainArticleTitle;
	}

	/** It's OK if this one isn't able to find anything. Not a critical error. It can return blank. */
	function getTopicDescriptionWikicode($callerPageWikicode) {
		preg_match('/===(\n.*?)\{\{/s', $callerPageWikicode, $matches);
		$output = $matches ? $matches[1] : '';
		if ( $output ) {
			$output = str_replace('<!---<noinclude>--->', '', $output);
			$output = str_replace('<!---</noinclude>--->', '', $output);
			$output = str_replace('<noinclude>', '', $output);
			$output = str_replace('</noinclude>', '', $output);
			$output = trim($output);
			$output = '<noinclude>' . $output . '</noinclude>';
		}
		//$this->eh->echoAndFlush($output, 'variable');
		return $output;
	}

	function getTopicWikipediaPageTitle($mainArticleTitle, $goodOrFeatured) {
		// assert($goodOrFeatured == 'good' || $goodOrFeatured == 'featured');
		// return 'Wikipedia:' . ucfirst($goodOrFeatured) . ' topics/' . $mainArticleTitle;
		return "Wikipedia:Featured topics/$mainArticleTitle";
	}

	function getTopicWikipediaPageWikicode($topicDescriptionWikicode, $topicBoxWikicode) {
		// Put only one line break. More than one line break causes excess whitespace when the page is transcluded into other pages in step 6.
		$output = trim($topicDescriptionWikicode . "\n" . $topicBoxWikicode);
		return $output;
	}

	function getDatetime() {
		date_default_timezone_set('UTC');
		$date = date('H:m, j F Y');
		// $this->eh->echoAndFlush($date, 'variable');
		return $date;
	}

	function getAllArticleTitles($topicBoxWikicode, $title) {
		// Confirmed that it's just FA, GA, FL. There won't be any other icons.
		preg_match_all("/{{\s*(?:class)?icon\s*\|\s*(?:FA|GA|FL)\}\}\s*(.*)\s*$/im", $topicBoxWikicode, $matches);
		if ( ! $matches[1] ) {
			throw new GiveUpOnThisTopic("On page $title, could not find list of topics inside of {{Featured topic box}}.");
		}
		$listOfTitles = $matches[1];
		$this->eh->html_var_export($listOfTitles, 'variable');
		
		// parse each potential title
		foreach ( $listOfTitles as $key => $title2 ) {
			// throw an error if any of the article names are templates, or not article links
			if ( strpos($title, '{') !== false || strpos($title, '}') !== false ) {
				throw new GiveUpOnThisTopic("On page $title, when parsing the list of topics in {{featured topic box}}, found some templates. Try subst:-ing them, then re-running the bot.");
			}
			
			// get rid of wikilink syntax around it
			$match = $this->sh->preg_first_match('/\[\[([^\|\]]*)(?:\|[^\|\]]*)?\]\]/is', $title2);
			if ( ! $match ) {
				throw new GiveUpOnThisTopic("On page $title, when parsing the list of topics in {{featured topic box}}, found an improperly formatted title. No wikilink found.");
			}
			$listOfTitles[$key] = $match;
		}
		
		// Good/featured topics should have at least 2 articles. If not, something is wrong.
		if ( count($listOfTitles) < 2 ) {
			throw new GiveUpOnThisTopic("On page $title, when parsing the list of topics in {{featured topic box}}, found less than 2 articles.");
		}
		
		$this->eh->html_var_export($listOfTitles, 'variable');
		return $listOfTitles;
	}

	function getTopicTalkPageWikicode($mainArticleTitle, $nonMainArticleTitles, $goodOrFeatured, $datetime, $wikiProjectBanners, $nominationPageTitle) {
		assert($goodOrFeatured == 'good' || $goodOrFeatured == 'featured');
		$nonMainArticleTitlestring = '';
		$count = 1;
		$lastArticleNumber = count($nonMainArticleTitles);
		foreach ( $nonMainArticleTitles as $key => $value ) {
			$and = '';
			if ( $count == $lastArticleNumber ) {
				$and = ' and';
			}
			$nonMainArticleTitlestring .= ",$and [[$value]]";
			$count++;
		}
		$actionCode = ($goodOrFeatured == 'good') ? 'GTC' : 'FTC';
		$talkWikicode = 
"{{Featuredtopictalk
|title = $mainArticleTitle
|action1 = $actionCode
|action1date = $datetime
|action1link = $nominationPageTitle
|action1result = '''Promoted''' with articles '''[[$mainArticleTitle]]'''$nonMainArticleTitlestring
|currentstatus = current
}}
$wikiProjectBanners";
		return $talkWikicode;
	}

	function getTopicTalkPageTitle($mainArticleTitle, $goodOrFeatured) {
		assert($goodOrFeatured == 'good' || $goodOrFeatured == 'featured');
		return 'Wikipedia talk:' . ucfirst($goodOrFeatured) . ' topics/' . $mainArticleTitle;
	}

	function getWikiProjectBanners($mainArticleTalkPageWikicode, $title) {
		preg_match_all('/\{\{WikiProject (?!banner)[^\}]*\}\}/i', $mainArticleTalkPageWikicode, $matches);
		if ( ! $matches ) {
			throw new GiveUpOnThisTopic("On page $title, could not find WikiProject banners on main article's talk page.");
		}
		$bannerWikicode = '';
		//$this->eh->html_var_export($matches, 'variable');
		foreach ( $matches[0] as $key => $value ) {
			$bannerWikicode .= $value . "\n";
		}
		$bannerWikicode = substr($bannerWikicode, 0, -1); // chop off last \n
		if ( count($matches[0]) > 1 ) {
			$bannerWikicode = "{{WikiProject banner shell|1=\n".$bannerWikicode."\n}}";
		}
		//$this->eh->echoAndFlush($bannerWikicode, 'variable');
		return $bannerWikicode;
	}

	function getNonMainArticleTitles($allArticleTitles, $mainArticleTitle) {
		return $this->deleteArrayValue($mainArticleTitle, $allArticleTitles);
	}

	function deleteArrayValue(string $needle, array $haystack) {
		return array_diff($haystack, [$needle]);
	}

	function abortIfTooManyArticlesInTopic($allArticleTitles, $MAX_ARTICLES_ALLOWED_IN_TOPIC, $title) {
		if ( count($allArticleTitles) > $MAX_ARTICLES_ALLOWED_IN_TOPIC ) {
			throw new GiveUpOnThisTopic("On page $title, too many topics in the topic box.");
		}
	}

	function removeGTCFTCTemplate($talkPageWikicode) {
		return preg_replace('/\{\{(?:gtc|ftc)[^\}]*\}\}\n/i', '', $talkPageWikicode);
	}

	/** Determine next |action= number in {{Article history}} template. This is so we can insert an action. */
	function determineNextActionNumber($talkPageWikicode, $ARTICLE_HISTORY_MAX_ACTIONS, $talkPageTitle) {
		for ( $i = $ARTICLE_HISTORY_MAX_ACTIONS; $i >= 1; $i-- ) {
			$hasAction = preg_match("/\|\s*action$i\s*=/i", $talkPageWikicode);
			if ( $hasAction ) {
				//$this->eh->echoAndFlush($i + 1, 'variable');
				return $i + 1;
			}
		}
		throw new GiveUpOnThisTopic("On page $talkPageTitle, in {{Article history}} template, unable to determine next |action= number.");
	}

	function updateArticleHistory($talkPageWikicode, $nextActionNumber, $goodOrFeatured, $datetime, $mainArticleTitle, $articleTitle, $talkPageTitle, $nominationPageTitle) {
		assert($goodOrFeatured == 'good' || $goodOrFeatured == 'featured');
		$main = ( $mainArticleTitle == $articleTitle ) ? 'yes' : 'no';
		$ftcOrGTC = ( $goodOrFeatured == 'featured' ) ? 'FTC' : 'GTC';
		$addToArticleHistory = 
"|action$nextActionNumber = $ftcOrGTC
|action{$nextActionNumber}date = $datetime
|action{$nextActionNumber}link = $nominationPageTitle
|action{$nextActionNumber}result = promoted
|ftname = $mainArticleTitle
|ftmain = $main";
		//$this->eh->echoAndFlush($addToArticleHistory, 'variable');
		//$this->eh->echoAndFlush($talkPageWikicode, 'variable');
		$newWikicode = $this->sh->insertCodeAtEndOfFirstTemplate($talkPageWikicode, 'Article ?history', $addToArticleHistory);
		if ( $newWikicode == $talkPageWikicode ) {
			throw new GiveUpOnThisTopic("On page $talkPageTitle, in {{Article history}} template, unable to determine where to add new actions.");
		}
		//$this->eh->html_var_export($matches, 'variable');
		//$this->eh->echoAndFlush($matches[1], 'variable');
		//$this->eh->echoAndFlush($addToArticleHistory, 'variable');
		//$this->eh->echoAndFlush($matches[2], 'variable');
		return $newWikicode;
	}

	/** There's a {{GA}} template that some people use instead of {{Article history}}. If this is present, replace it with {{Article history}}. */
	function addArticleHistoryIfNotPresent($talkPageWikicode, $talkPageTitle) {
		$hasArticleHistory = preg_match('/\{\{Article ? history([^\}]*)\}\}/i', $talkPageWikicode);
		$gaTemplateWikicode = $this->sh->preg_first_match('/(\{\{GA[^\}]*\}\})/i', $talkPageWikicode);
		//$this->eh->echoAndFlush($gaTemplateWikicode, 'variable');
		if ( ! $hasArticleHistory && $gaTemplateWikicode ) {
			// delete {{ga}} template
			$talkPageWikicode = preg_replace('/\{\{GA[^\}]*\}\}\n?/i', '', $talkPageWikicode);
			
			// parse its parameters
			// example: |21:00, 12 March 2017 (UTC)|topic=Sports and recreation|page=1|oldid=769997774
			$parameters = $this->getParametersFromTemplateWikicode($gaTemplateWikicode);
			
			// if no page specified, assume page is 1. so then the good article review link will be parsed as /GA1
			if ( ! $parameters['page'] ) {
				$parameters['page'] = 1;
			}
			
			$date = date('Y-m-d', strtotime($parameters[1]));
			
			// insert {{article history}} template
			$addToTalkPageEndOfLead = 
"{{Article history
|currentstatus = GA
|topic = {$parameters['topic']}

|action1 = GAN
|action1date = $date
|action1link = $talkPageTitle/GA{$parameters['page']}
|action1result = listed
|action1oldid = {$parameters['oldid']}
}}";
			$talkPageWikicode = $this->addToTalkPageEndOfLead($talkPageWikicode, $addToTalkPageEndOfLead);
		}
		return $talkPageWikicode;
	}

	/** Add wikicode right above the first ==Header== if present, or at bottom of page. Treat {{Talk:abc/GA1}} as a header. */
	function addToTalkPageEndOfLead($talkPageWikicode, $wikicodeToAdd) {
		if ( ! $talkPageWikicode ) {
			return $wikicodeToAdd;
		}
		
		// Find first heading
		$headingLocation = strpos($talkPageWikicode, '==');
		
		// Find first {{Talk:abc/GA1}} template
		$gaTemplateLocation = $this->sh->preg_position('/{{[^\}]*\/GA\d{1,2}}}/is', $talkPageWikicode);
		
		// Set insert location
		if ( $headingLocation !== false ) {
			$insertPosition = $headingLocation;
		} elseif ( $gaTemplateLocation !== false ) {
			$insertPosition = $gaTemplateLocation;
		} else {
			$insertPosition = strlen($talkPageWikicode);
		}
		
		// if there's a {{Talk:abc/GA1}} above a heading, adjust for this
		if (
			$headingLocation !== false &&
			$gaTemplateLocation !== false &&
			$gaTemplateLocation < $headingLocation
		) {
			$insertPosition = $gaTemplateLocation;
		}
		
		// If there's whitespace in front of the insert location, back up, up to 2 spaces
		$twoSpacesBefore = substr($talkPageWikicode, $insertPosition - 2, 1);
		$oneSpaceBefore = substr($talkPageWikicode, $insertPosition - 1, 1);
		if ( $oneSpaceBefore == "\n" && $twoSpacesBefore == "\n" ) {
			$insertPosition -= 2;
		} elseif ( $oneSpaceBefore == "\n" ) {
			$insertPosition -= 1;
		}
		
		$lengthOfRightHalf = strlen($talkPageWikicode) - $insertPosition;
		$leftHalf = substr($talkPageWikicode, 0, $insertPosition);
		$rightHalf = substr($talkPageWikicode, $insertPosition, $lengthOfRightHalf);
		
		if ( $insertPosition == 0 ) {
			return $wikicodeToAdd . "\n" . $talkPageWikicode;
		} else {
			return $leftHalf . "\n" . $wikicodeToAdd . $rightHalf;
		}
	}

	function getParametersFromTemplateWikicode($wikicode) {
		$wikicode = substr($wikicode, 2, -2); // remove {{ and }}
		// TODO: explode without exploding | inside of inner templates
		$strings = explode('|', $wikicode);
		$parameters = [];
		$unnamedParameterCount = 1;
		$i = 0;
		foreach ( $strings as $key => $string ) {
			$i++;
			if ( $i == 1 ) continue; // skip the template name, this is not a parameter 
			$hasEquals = strpos($string, '=');
			if ( $hasEquals === false ) {
				$parameters[$unnamedParameterCount] = $string;
				$unnamedParameterCount++;
			} else {
				preg_match('/^([^=]*)=(.*)$/s', $string, $matches); // isolate param name and param value by looking for first equals sign
				$paramName = strtolower(trim($matches[1]));
				$paramValue = trim($matches[2]);
				$parameters[$paramName] = $paramValue;
			}
		}
		//$this->eh->html_var_export($parameters, 'variable');
		return $parameters;
	}

	function updateCountPageTopicCount($countPageWikicode, $countPageTitle) {
		$count = $this->sh->preg_first_match("/currently '''([,\d]+)'''/", $countPageWikicode);
		$count = str_replace(',', '', $count); // remove commas
		if ( ! $count ) {
			throw new GiveUpOnThisTopic("On page $countPageTitle, unable to find the total topic count.");
		}
		$count++;
		$count = number_format($count); // add commas back
		$countPageWikicode = preg_replace("/(currently ''')([,\d]+)(''')/", '${1}'.$count.'${3}', $countPageWikicode);
		return $countPageWikicode;
	}

	function updateCountPageArticleCount($countPageWikicode, $countPageTitle, $articlesInTopic) {
		$count = $this->sh->preg_first_match("/encompass '''([,\d]+)'''/", $countPageWikicode);
		$count = str_replace(',', '', $count); // remove commas
		if ( ! $count ) {
			throw new GiveUpOnThisTopic("On page $countPageTitle, unable to find the total article count.");
		}
		$count += $articlesInTopic;
		$count = number_format($count); // add commas back
		$countPageWikicode = preg_replace("/(encompass ''')([,\d]+)(''')/", '${1}'.$count.'${3}', $countPageWikicode);
		return $countPageWikicode;
	}

	function getLogPageTitle($datetime, $goodOrFeatured) {
		$goodOrFeatured = ucfirst($goodOrFeatured);
		$monthAndYear = date('F Y', strtotime($datetime));
		return "Wikipedia:Featured and good topic candidates/$goodOrFeatured log/$monthAndYear";
	}

	function addTopicToGoingsOn($goingsOnTitle, $goingsOnWikicode, $topicWikipediaPageTitle, $mainArticleTitle) {
		$newWikicode = preg_replace("/('''\[\[Wikipedia:Featured topics\|Topics]] that gained featured status'''.*?)(\|})/s", "$1* [[$topicWikipediaPageTitle|$mainArticleTitle]]\n$2", $goingsOnWikicode);
		if ( $newWikicode == $goingsOnWikicode ) {
			throw new GiveUpOnThisTopic("On page $goingsOnTitle, unable to figure out where to insert code.");
		}
		return $newWikicode;
	}

	function addTopicToNewFeaturedContent($newFeaturedContentTitle, $newFeaturedContentWikicode, $topicWikipediaPageTitle, $mainArticleTitle) {
		$newWikicode = preg_replace("/(<!-- Topics \(15, most recent first\) -->)/", "$1\n* [[$topicWikipediaPageTitle|$mainArticleTitle]]", $newFeaturedContentWikicode);
		if ( $newWikicode == $newFeaturedContentWikicode ) {
			throw new GiveUpOnThisTopic("On page $newFeaturedContentTitle, unable to figure out where to insert code.");
		}
		return $newWikicode;
	}

	function removeBottomTopicFromNewFeaturedContent($newFeaturedContentTitle, $newFeaturedContentWikicode) {
		$wikicode15MostRecentTopics = $this->sh->preg_first_match("/<!-- Topics \(15, most recent first\) -->\n(.*?)<\/div>/s", $newFeaturedContentWikicode);
		$wikicode15MostRecentTopics = trim($wikicode15MostRecentTopics);
		if ( ! $wikicode15MostRecentTopics ) {
			throw new GiveUpOnThisTopic("On page $newFeaturedContentTitle, unable to find wikicode for 15 most recent topics.");
		}
		$wikicode15MostRecentTopics = $this->sh->deleteLastLineOfString($wikicode15MostRecentTopics);
		$newWikicode = preg_replace("/(<!-- Topics \(15, most recent first\) -->\n)(.*?)(<\/div>)/s", "$1$wikicode15MostRecentTopics\n\n$3", $newFeaturedContentWikicode);
		if ( $newWikicode == $newFeaturedContentWikicode ) {
			throw new GiveUpOnThisTopic("On page $newFeaturedContentTitle, unable to delete oldest topic.");
		}
		return $newWikicode;
	}

	function getGoodArticleCount($topicBoxWikicode) {
		preg_match_all('/{{\s*(?:class)?icon\s*\|\s*(?:GA)\s*}}/i', $topicBoxWikicode, $matches);
		$count = count($matches[0]);
		//$this->eh->echoAndFlush(var_export($matches, true), 'variable');
		$this->eh->echoAndFlush($count, 'variable');
		return $count;
	}

	function getFeaturedArticleCount($topicBoxWikicode) {
		preg_match_all('/{{\s*(?:class)?icon\s*\|\s*(?:FA|FL)\s*}}/i', $topicBoxWikicode, $matches);
		$count = count($matches[0]);
		//$this->eh->echoAndFlush(var_export($matches, true), 'variable');
		$this->eh->echoAndFlush($count, 'variable');
		return $count;
	}

	/*
	function addHeadingIfNeeded($talkPageWikicode, $talkPageTitle) {
		$newWikicode = $talkPageWikicode;
		$hasHeadings = ( strpos($talkPageWikicode, '==') !== false );
		$hasTranscludedGA1Page = ( strpos($talkPageWikicode, '/GA1}}') !== false );
		if ( ! $hasHeadings && $hasTranscludedGA1Page ) {
			$newWikicode = preg_replace("/(\{\{Talk:[^\/]+\/GA1}}\s*)$/i", "== Good article ==\n$1", $newWikicode);
			if ( $newWikicode == $talkPageWikicode ) {
				throw new GiveUpOnThisTopic("On page $talkPageTitle, unable to add a heading above {{Talk:foobar/GA1}}");
			}
		}
		return $newWikicode;
	}
	*/

	function writeSuccessOrError($nominationPageWikicode, $nominationPageTitle) {
		
	}

	/** In the {{Featured topic box}} template, makes sure that it has the parameter view=yes. For example, {{Featured topic box|view=yes}} */
	function setTopicBoxViewParamterToYes($topicBoxWikicode) {
		$hasViewYes = preg_match('/\|\s*view\s*=\s*yes\s*[\|\}]/si', $topicBoxWikicode);
		if ( $hasViewYes ) return $topicBoxWikicode;
		// delete view = anything
		$topicBoxWikicode = preg_replace('/\|\s*view\s*=[^\|\}]*([\|\}])/si', '$1', $topicBoxWikicode);
		// if the template ended up as {{Template\n}}, get rid of the \n
		$topicBoxWikicode = preg_replace('/({{.*)\n{1,}(}})/i', '$1$2', $topicBoxWikicode);
		// add view = yes
		$topicBoxWikicode = $this->sh->insertCodeAtEndOfFirstTemplate($topicBoxWikicode, 'Featured topic box', '|view=yes');
		return $topicBoxWikicode;
	}

	/** In the {{Featured topic box}} template, makes sure that if the title parameter has something like |title=''Meet the Who 2'', that the '' is removed so that the "discuss" link isn't broken. */
	function cleanTopicBoxTitleParameter($topicBoxWikicode) {
		return preg_replace("/(\|\s*title\s*=\s*)''([^\|\}]*)''(\s*[\|\}])/is", '$1$2$3', $topicBoxWikicode);
	}

	/** Topic descriptions should not have user signatures. Strip these out. */
	function removeSignaturesFromTopicDescription($topicDescriptionWikicode) {
		return preg_replace("/ \[\[User:.*\(UTC\)/is", '', $topicDescriptionWikicode);
	}

	/** Takes the wikicode of the page [[Wikipedia:Featured and good topic candidates]], and removes the nomination page from it. For example, if the nomination page title is "Wikipedia:Featured and good topic candidates/Meet the Woo 2/archive1", it will remove {{Wikipedia:Featured and good topic candidates/Meet the Woo 2/archive1}} from the page. */
	function removeTopicFromFGTC($nominationPageTitle, $fgtcWikicode, $fgtcTitle) {
		$wikicode2 = str_replace("{{" . $nominationPageTitle . "}}\n", '', $fgtcWikicode);
		$wikicode2 = str_replace("\n{{" . $nominationPageTitle . "}}", '', $wikicode2);
		if ( $fgtcWikicode == $wikicode2 ) {
			throw new GiveUpOnThisTopic("On page $fgtcTitle, unable to locate {{" . $nominationPageTitle . "}}.");
		}
		return $wikicode2;
	}
}