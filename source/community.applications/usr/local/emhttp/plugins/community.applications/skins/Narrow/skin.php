<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2022, Andrew Zawadzki #
#                   Licenced under GPLv2                      #
#                                                             #
###############################################################

function display_apps($pageNumber=1,$selectedApps=false,$startup=false) {
	global $caPaths, $caSettings, $sortOrder;

	if ( is_file($caPaths['repositoriesDisplayed']) ) {
		$file = readJsonFile($caPaths['repositoriesDisplayed']);
		//$startup = true;
	} else {
		if ( is_file($caPaths['community-templates-catSearchResults']) )
			$file = readJsonFile($caPaths['community-templates-catSearchResults']);
		else
			$file = readJsonFile($caPaths['community-templates-displayed']);
	}
	$communityApplications = is_array($file['community']) ? $file['community'] : array();
	$totalApplications = count($communityApplications);

	$display = ( $totalApplications ) ? my_display_apps($communityApplications,$pageNumber,$selectedApps,$startup) : "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>$('.multi_installDiv').hide();hideSortIcons();</script>";

	return $display;
}

function my_display_apps($file,$pageNumber=1,$selectedApps=false,$startup=false) {
	global $caPaths, $caSettings, $plugin, $displayDeprecated, $sortOrder, $DockerTemplates, $DockerClient;

	$dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
	$repositories = readJsonFile($caPaths['repositoryList']);
	$extraBlacklist = readJsonFile($caPaths['extraBlacklist']);
	$extraDeprecated = readJsonFile($caPaths['extraDeprecated']);

	if ( is_file("/var/run/dockerd.pid") && is_dir("/proc/".@file_get_contents("/var/run/dockerd.pid")) ) {
		$caSettings['dockerRunning'] = "true";
		$info = getAllInfo();
		$dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
	} else {
		unset($caSettings['dockerRunning']);
		$info = array();
		$dockerUpdateStatus = array();
	}

	if ( ! $selectedApps )
		$selectedApps = array();

	$dockerWarningFlag = $dockerNotEnabled = (! $caSettings['dockerRunning'] && ! $caSettings['NoInstalls']) ? "true" : "false";

	if ( $dockerNotEnabled == "true" ) {
		$unRaidVars = parse_ini_file($caPaths['unRaidVars']);
		$dockerVars = parse_ini_file($caPaths['docker_cfg']);
		file_put_contents("/tmp/blah",print_r($dockerVars,true));
		if ( $unRaidVars['mdState'] == "STARTED" && $dockerVars['DOCKER_ENABLED'] !== "yes" )
			$dockerNotEnabled = 1; // Array started, docker not enabled
		if ( $unRaidVars['mdState'] == "STARTED" && $dockerVars['DOCKER_ENABLED'] == "yes" )
			$dockerNotEnabled = 2; // Docker failed to start
		if ( $unRaidVars['mdState'] !== "STARTED" )
			$dockerNotEnabled = 3; // Array not started
	}
	$displayHeader = "<script>addDockerWarning($dockerNotEnabled);var dockerNotEnabled = $dockerWarningFlag;</script>";

	$pinnedApps = readJsonFile($caPaths['pinnedV2']);

	$checkedOffApps = arrayEntriesToObject(@array_merge(@array_values($selectedApps['docker']),@array_values($selectedApps['plugin'])));

	$columnNumber = 0;
	$appCount = 0;
	$startingApp = ($pageNumber -1) * $caSettings['maxPerPage'] + 1;
	$startingAppCounter = 0;

	$displayedTemplates = array();
	foreach ($file as $template) {
/* 		if ( $template['Blacklist'] && ! $template['NoInstall'] )
			continue; */
		
		$startingAppCounter++;
		if ( $startingAppCounter < $startingApp ) continue;
		$displayedTemplates[] = $template;
	}

	$currentServer = @file_get_contents($caPaths['currentServer']);

	# Create entries for skins.
	foreach ($displayedTemplates as $template) {
		if ( ! $template['Blacklist'] ) {
			if ( $extraBlacklist[$template['Repository']] ) {
				$template['Blacklist'] = true;
				$template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
			}
		}
		if ( ! $template['Deprecated'] && $extraDeprecated[$template['Repository']] ) {
			$template['Deprecated'] = true;
			$template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
		}
		
		if ( $template['RepositoryTemplate'] ) {
			$template['Icon'] = $template['icon'] ?: "/plugins/dynamix.docker.manager/images/question.png";

			if ( ! $template['bio'] )
				$template['CardDescription'] = tr("No description present");
			else {
				$template['bio'] = strip_tags(markdown($template['bio']));
				$template['Description'] = $template['bio'];
			}
			$template['display_dockerName'] = $template['RepoName'];

			$favClass = ( $caSettings['favourite'] && ($caSettings['favourite'] == $template['RepoName']) ) ? "ca_favouriteRepo" : "ca_non_favouriteRepo";
			$template['ca_fav'] = $caSettings['favourite'] && ($caSettings['favourite'] == $template['RepoName']);
			$niceRepoName = str_replace("'s Repository","",$template['RepoName']);
			$niceRepoName = str_replace("' Repository","",$niceRepoName);
			$niceRepoName = str_replace(" Repository","",$niceRepoName);
			$favMsg = ($favClass == "ca_favouriteRepo") ? tr("Click to remove favourite repository") : tr(sprintf("Click to set %s as favourite repository",$niceRepoName));

			$ct .= displayCard($template);
			$count++;
			if ( $count == $caSettings['maxPerPage'] ) break;
		} else {
			$actionsContext = [];
			$selected = false;
			$installComment = $template['CAComment'];

			if ( $template['Requires'] )
				$installComment = tr("This application has additional requirements")."<br>".markdown($template['Requires'])."<br>$installComment";

			if ( ! $template['Language'] ) {
				if ( ! $template['NoInstall'] && ! $caSettings['NoInstalls']) {
					if ( ! $template['Plugin'] ) {
						if ( $caSettings['dockerRunning'] ) {
							foreach ($info as $testDocker) {
								$tmpRepo = strpos($template['Repository'],":") ? $template['Repository'] : $template['Repository'].":latest";
								$tmpRepo = strpos($tmpRepo,"/") ? $tmpRepo : "library/$tmpRepo";
								if ( ( ($tmpRepo == $testDocker['Image'] && $template['Name'] == $testDocker['Name']) || "{$tmpRepo}:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name']) ) {
									$selected = true;
									$name = $testDocker['Name'];
									break;
								}
							}

							if ( $selected ) {
								$ind = searchArray($info,"Name",$name);
								if ( $info[$ind]['url'] && $info[$ind]['running'] ) {
									$actionsContext[] = array("icon"=>"ca_fa-globe","text"=>"WebUI","action"=>"openNewWindow('{$info[$ind]['url']}','_blank');");
								}

								if ( $dockerUpdateStatus[$tmpRepo]['status'] == "false" ) {
									$template['UpdateAvailable'] = true;
									$actionsContext[] = array("icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"updateDocker('$name');");
								} else {
									$template['UpdateAvailable'] = false;
								}
								if ( $caSettings['defaultReinstall'] == "true" && ! $template['Blacklist']) {
									if ( $template['ID'] !== false ) { # don't allow 2nd if there's not a "default" within CA
										if ( $template['BranchID'] )
											$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"displayTags('{$template['ID']}',true,'".str_replace(" ","&#32;",htmlspecialchars($installComment))."','".portsUsed($template)."');");
										else
											$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','second','".str_replace(" ","&#32;",htmlspecialchars($installComment))."','".portsUsed($template)."');");
									}
								}
								$actionsContext[] = array("icon"=>"ca_fa-edit","text"=>tr("Edit"),"action"=>"popupInstallXML('".addslashes($info[$ind]['template'])."','edit');");
								$actionsContext[] = array("divider"=>true);
								if ($info[$ind]['template'])
									$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>tr("Uninstall"),"action"=>"uninstallDocker('".addslashes($info[$ind]['template'])."','{$template['Name']}');");
								if ( $template['DonateLink'] ) {
									$actionsContext[] = array("divider"=>true);
									$actionsContext[] = array("icon"=>"ca_fa-money","text"=>tr("Donate"),"action"=>"openNewWindow('".addslashes($template['DonateLink'])."','_blank');");
								}
							} elseif ( ! $template['Blacklist'] || ! $template['Compatible']) {
								if ( $template['InstallPath'] ) {
									$userTemplate = readXmlFile($template['InstallPath'],false,false);
									if ( ! $template['Blacklist'] ) {
										$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Reinstall"),"action"=>"popupInstallXML('".addslashes($template['InstallPath'])."','user','','".portsUsed($userTemplate)."');");
										$actionsContext[] = array("divider"=>true);
									}
									$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>tr("Remove from Previous Apps"),"alternate"=>tr("Remove"),"action"=>"removeApp('{$template['InstallPath']}','{$template['Name']}');");
								}	else {
									if ( ! $template['BranchID'] ) {
										if ( is_file("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml") ) {
											$test = readXmlFile("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml",true);
											if ( $template['Repository'] == $test['Repository'] ) {
												$userTemplate = readXmlFile($template['InstallPath'],false,false);
												$actionsContext[] = array("icon"=>"ca_fa-install","text"=>"<span class='ca_red'>".tr("Reinstall From Previous Apps")."</span>","action"=>"popupInstallXML('".addslashes("{$caPaths['dockerManTemplates']}/my-{$template['Name']}").".xml','user','','".portsUsed($userTemplate)."');");
												$actionsContext[] = array("divider"=>true);
											}
										}
										$actionsContext[] = array("icon"=>"ca_fa-install","text"=>"Install","action"=>"popupInstallXML('".addslashes($template['Path'])."','default','".str_replace(" ","&#32",htmlspecialchars($installComment))."','".portsUsed($template)."');");
									} else {
										$actionsContext[] = array("icon"=>"ca_fa-install","text"=>"Install","action"=>"displayTags('{$template['ID']}',false,'".str_replace(" ","&#32",htmlspecialchars($installComment))."','".portsUsed($template)."');");
									}
								}
							}
						}
					} else {
						$pluginName = basename($template['PluginURL']);
						if ( file_exists("/var/log/plugins/$pluginName") ) {
							if ( ( strcmp(plugin("version","/var/log/plugins/$pluginName"),$template['pluginVersion']) < 0 || $template['UpdateAvailable']) && $template['Name'] !== "Community Applications") {
								@copy($caPaths['pluginTempDownload'],"/tmp/plugins/$pluginName");
								$template['UpdateAvailable'] = true;
								$actionsContext[] = array("icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"installPlugin('$pluginName',true);");
							} else {
								if ( ! $template['UpdateAvailable'] ) # this handles if the feed hasn't caught up to the update yet
									$template['UpdateAvailable'] = false;
							}
							$pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : plugin("launch","/var/log/plugins/$pluginName");
							if ( $pluginSettings ) {
								$actionsContext[] = array("icon"=>"ca_fa-pluginSettings","text"=>tr("Settings"),"action"=>"openNewWindow('/Apps/$pluginSettings');");
							}

							if ( $pluginName != "community.applications.plg" ) {
								if ( ! empty($actionsContext) )
									$actionsContext[] = array("divider"=>true);

								$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>tr("Uninstall"),"action"=>"uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ","&#32;",$template['Name'])."');");
							}
							if ( $template['DonateLink'] ) {
									$actionsContext[] = array("divider"=>true);
									$actionsContext[] = array("icon"=>"ca_fa-money","text"=>tr("Donate"),"action"=>"openNewWindow('".addslashes($template['DonateLink'])."','_blank');");
							}
						} elseif ( ! $template['Blacklist'] || ! $template['Compatible'] ) {
							$buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
							if ( ! $template['InstallPath'] ) {
								$installComment = $template['CAComment'];
								if ( ! $installComment && $template['Requires'] ){
									// Remove the flags to indicate a search taking place
									preg_match_all("/\/\/(.*?)\\\\/m",$template['Requires'],$searchMatches);
									if ( count($searchMatches[1]) ) {
										foreach ($searchMatches[1] as $searchResult) {
											$template['Requires'] = str_replace("//$searchResult\\\\",$searchResult,$template['Requires']);
										}
									}
									$installComment = tr("This application has additional requirements")."<br>".markdown($template['Requires']);
								}
							}
							if ( ! $template['RequiresFile'] || ($template['RequiresFile'] && is_file($template['RequiresFile'])) ) {
								$installComment = $template['RequiresFile'] ? "" : $installComment;
								$actionsContext[] = array("icon"=>"ca_fa-install","text"=>$buttonTitle,"action"=>"installPlugin('{$template['PluginURL']}','','".str_replace([" ","\n"],["&#32;",""],htmlspecialchars($installComment))."');");
							}
							if ( $template['InstallPath'] ) {
								if ( ! empty($actionsContext) )
									$actionsContext[] = array("divider"=>true);
								$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>tr("Remove from Previous Apps"),"action"=>"removeApp('{$template['InstallPath']}','$pluginName');");
							}
						}
					}
				}
			}
			if ( $template['Language'] ) {
				$countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
				$dynamixSettings = @parse_ini_file($caPaths['dynamixSettings'],true);
				$currentLanguage = $dynamixSettings['display']['locale'] ?: "en_US";
				$installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"),array(".",".."));
				$installedLanguages = array_filter($installedLanguages,function($v) {
					return is_dir("/usr/local/emhttp/languages/$v");
				});
				$installedLanguages[] = "en_US";
				$currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage") ) ? $currentLanguage : "en_US";
				if ( in_array($countryCode,$installedLanguages) ) {
					if ( $currentLanguage != $countryCode ) {
						$actionsContext[] = array("icon"=>"ca_fa-switchto","text"=>$template['SwitchLanguage'],"action"=>"CAswitchLanguage('$countryCode');");
					}
				} else {
					$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"installLanguage('{$template['TemplateURL']}','$countryCode');");
				}
				if ( file_exists("/var/log/plugins/lang-$countryCode.xml") ) {
					$template['Installed'] = true;
					if ( languageCheck($template) ) {
						$template['UpdateAvailable'] = true;
						$actionsContext[] = array("icon"=>"ca_fa-update","text"=>$template['UpdateLanguage'],"action"=>"updateLanguage('$countryCode');");
					}
					if ( $currentLanguage != $countryCode ) {
						if ( ! empty($actionsContext) )
							$actionsContext[] = array("divider"=>true);
						$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>tr("Remove Language Pack"),"action"=>"removeLanguage('$countryCode');");
					}
				}
			}

			$template['actionsContext'] = $actionsContext;

			$template['ca_fav'] = $caSettings['favourite'] && ($caSettings['favourite'] == $template['RepoName']);
			if ( strpos($template['Repository'],"/") === false )
				$template['Pinned'] = $pinnedApps["library/{$template['Repository']}&{$template['SortName']}"];
			else
				$template['Pinned'] = $pinnedApps["{$template['Repository']}&{$template['SortName']}"];
			$template['Twitter'] = $template['Twitter'] ?: $repositories[$template['Repo']]['Twitter'];
			$template['Reddit'] = $template['Reddit'] ?: $repositories[$template['Repo']]['Reddit'];
			$template['Facebook'] = $template['Facebook'] ?: $repositories[$template['Repo']]['Facebook'];
			$template['Discord'] = $template['Discord'] ?: $repositories[$template['RepoName']]['Discord'];

			$template['checked'] = $checkedOffApps[$previousAppName] ? "checked" : "";

			if ( ! $template['Plugin'] ) {
				$tmpRepo = $template['Repository'];
				if ( ! strpos($tmpRepo,"/") ) {
					$tmpRepo = "library/$tmpRepo";
				}
				foreach ($info as $testDocker) {
					if ( ($tmpRepo == $testDocker['Image'] || "$tmpRepo:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name']) ) {
						$template['Installed'] = true;

						break;
					}
				}
			} else {
				$pluginName = basename($template['PluginURL']);
				$template['Installed'] = checkInstalledPlugin($template) ;

			}

			if ( $template['Language'] ) {
				$template['Installed'] = is_dir("{$caPaths['languageInstalled']}{$template['LanguagePack']}") && ! $template['Uninstall'];
			}

			if ( startsWith($template['Repository'],"library/") || startsWith($template['Repository'],"registry.hub.docker.com/library/") || strpos($template['Repository'],"/") === false)
				$template['Official'] = true;

	# Entries created.  Now display it
			$ct .= displayCard($template);
			$count++;
			if ( $count == $caSettings['maxPerPage'] ) break;
		}
	}

	$ct .= getPageNavigation($pageNumber,count($file),false,true);

	if ( ! $count )
		$displayHeader .= "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>hideSortIcons();</script>";

	if ( $count == 1 ) {
		if ( $template['RepositoryTemplate'] ) {
			$displayHeader .= "<script>showRepoPopup('".htmlentities($template['RepoName'],ENT_QUOTES)."');</script>";
		} else {
			if ($template['InstallPath'])
				$template['Path'] = $template['InstallPath'];

			$displayHeader .= "<script>showSidebarApp('{$template['Path']}','{$template['Name']}');</script>";
		}
	}
	// Handle MaxPerPage changing on a different tab
	$displayHeader .= "<script>changeMax({$caSettings['maxPerPage']});</script>";

	return "$displayHeader$ct";
}

function getPageNavigation($pageNumber,$totalApps,$dockerSearch,$displayCount = true) {
	global $caSettings;

	$pageFunction = $dockerSearch ? "dockerSearch": "changePage";
	if ( $dockerSearch )
		$caSettings['maxPerPage'] = 25;

	if ( $caSettings['maxPerPage'] < 0 ) return;
	$swipeScript = "<script>";

	$totalPages = ceil($totalApps / $caSettings['maxPerPage']);

	if ($totalPages <= 1) return "<script>data.currentpage = 1;</script>";

	$startApp = ($pageNumber - 1) * $caSettings['maxPerPage'] + 1;
	$endApp = $pageNumber * $caSettings['maxPerPage'];
	if ( $endApp > $totalApps )
		$endApp = $totalApps;

	$o = "</div><div class='ca_center'>";
	if ($displayCount)
		$o .= "<span class='pageNavigation'>".sprintf(tr("Displaying %s - %s (of %s)"),$startApp,$endApp,$totalApps)."</span><br>";

	$o .= "<div class='pageNavigation'>";
	$previousPage = $pageNumber - 1;
	$o .= ( $pageNumber == 1 ) ? "<span class='pageLeft pageNumber pageNavNoClick'></span>" : "<span class='pageLeft ca_tooltip pageNumber' onclick='$pageFunction(&quot;$previousPage&quot;)'></span>";
	$swipeScript .= "data.prevpage = $previousPage;";
	$startingPage = $pageNumber - 5;
	if ($startingPage < 3 )
		$startingPage = 1;
	else
		$o .= "<a class='ca_tooltip pageNumber' onclick='$pageFunction(&quot;1&quot;);'>1</a><span class='pageNumber pageDots'></span>";

	$endingPage = $pageNumber + 5;
	if ( $endingPage > $totalPages )
		$endingPage = $totalPages;

	for ($i = $startingPage; $i <= $endingPage; $i++)
		$o .= ( $i == $pageNumber ) ? "<span class='pageNumber pageSelected'>$i</span>" : "<a class='ca_tooltip pageNumber' onclick='$pageFunction(&quot;$i&quot;);'>$i</a>";

	if ( $endingPage != $totalPages) {
		if ( ($totalPages - $pageNumber ) > 6)
			$o .= "<span class='pageNumber pageDots'></span>";

		if ( ($totalPages - $pageNumber ) >5 )
			$o .= "<a class='ca_tooltip pageNumber' onclick='$pageFunction(&quot;$totalPages&quot;);'>$totalPages</a>";
	}
	$nextPage = $pageNumber + 1;
	$o .= ( $pageNumber < $totalPages ) ? "<span class='ca_tooltip pageNumber pageRight' onclick='$pageFunction(&quot;$nextPage&quot;);'></span>" : "<span class='pageRight pageNumber pageNavNoClick'></span>";
	$swipeScript .= ( $pageNumber < $totalPages ) ? "data.nextpage = $nextPage;" : "data.nextpage = 0;";
	$swipeScript .= "</script>";
	$o .= "</div></div><script>data.currentpage = $pageNumber;</script>";
	return $o.$swipeScript;
}


######################################
# Generate the display for the popup #
######################################
function getPopupDescriptionSkin($appNumber) {
	global $caSettings, $caPaths, $language, $DockerTemplates, $DockerClient;

	$unRaidVars = parse_ini_file($caPaths['unRaidVars']);
	$dockerVars = parse_ini_file($caPaths['docker_cfg']);
	$csrf_token = $unRaidVars['csrf_token'];
	$tabMode = '_parent';

	$allRepositories = readJsonFile($caPaths['repositoryList']);
	$extraBlacklist = readJsonFile($caPaths['extraBlacklist']);
	$extraDeprecated = readJsonFile($caPaths['extraDeprecated']);
		
	$pinnedApps = readJsonFile($caPaths['pinnedV2']);
	if ( ! is_file($caPaths['statistics']) )
		download_json($caPaths['statisticsURL'],$caPaths['statistics']);

	if ( is_file("/var/run/dockerd.pid") && is_dir("/proc/".@file_get_contents("/var/run/dockerd.pid")) ) {
		$caSettings['dockerRunning'] = "true";
		$infoTmp = getAllInfo();
		foreach ($infoTmp as $container) {
			$info[$container['Name']] = $container;
		}
		$dockerRunning = $DockerClient->getDockerContainers();
		$dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
	} else {
		unset($caSettings['dockerRunning']);
		$info = array();
		$dockerRunning = array();
		$dockerUpdateStatus = array();
	}
	if ( ! is_file($caPaths['warningAccepted']) )
		$caSettings['NoInstalls'] = true;

	# $appNumber is actually the path to the template.  It's pretty much always going to be the same even if the database is out of sync.
	if ( is_file($caPaths['community-templates-allSearchResults']) )
		$displayed = readJsonFile($caPaths['community-templates-allSearchResults']);
	else
		$displayed = readJsonFile($caPaths['community-templates-displayed']);

	$index = searchArray($displayed['community'],"InstallPath",$appNumber);
	if ( $index === false ) {
		$ind = $index;
		while ( true ) {
			$template = $displayed[$ind];
			if ( $template['Name'] == $displayed['community'][$ind]['Name'] ) {
				$index = $ind;
				break;
			}
			$ind = searchArray($displayed['community'],"Path",$appNumber,$ind+1);
			if ( $ind === false ) {
				unset($template);
				break;
			}
		}
	}

	if ( $index !== false ) {
		$template = $displayed['community'][$index];
	}

	# handle case where the app being asked to display isn't on the most recent displayed list (ie: multiple browser tabs open)
	if ( ! $template ) {
		$file = readJsonFile($caPaths['community-templates-info']);
		$index = searchArray($file,"Path",$appNumber);
		if ( $index === false ) {
			echo json_encode(array("description"=>tr("Something really wrong happened.  Reloading the Apps tab will probably fix the problem")));
			return;
		}
		$template = $file[$index];
	}
	$currentServer = file_get_contents($caPaths['currentServer']);

/* 	if ( $currentServer == "Primary Server" && $template['IconHTTPS'])
		$template['Icon'] = $template['IconHTTPS'];
 */
 
	if ( ! $template['Blacklist'] ) {
		if ( $extraBlacklist[$template['Repository']] ) {
			$template['Blacklist'] = true;
			$template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
		}
	}
	if ( ! $template['Deprecated'] && $extraDeprecated[$template['Repository']] ) {
		$template['Deprecated'] = true;
		$template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
	}
	
	$ID = $template['ID'];

	$template['Profile'] = $allRepositories[$template['RepoName']]['profile'];
	$template['ProfileIcon'] = $allRepositories[$template['RepoName']]['icon'];

	// Hack the system so that language's popups always appear in the appropriate language
	if ( $template['Language'] ) {
		$countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
		if ( $countryCode !== "en_US" ) {
			if ( ! is_file("{$caPaths['tempFiles']}/CA_language-$countryCode") ) {
				download_url("{$caPaths['CA_languageBase']}$countryCode","{$caPaths['tempFiles']}/CA_language-$countryCode");
			}
			$language = is_file("{$caPaths['tempFiles']}/CA_language-$countryCode") ? @parse_lang_file("{$caPaths['tempFiles']}/CA_language-$countryCode") : [];
		} else {
			$language = [];
		}
	}

	$donatelink = $template['DonateLink'];
	if ( $donatelink ) {
		$donatetext = $template['DonateText'];
	}

	if ( ! $template['Plugin'] ) {
		if ( ! strpos($template['Repository'],"/") ) {
			$template['Repository'] = "library/{$template['Repository']}";
		}
		foreach ($dockerRunning as $testDocker) {
			if ( ($template['Repository'] == $testDocker['Image'] || "{$template['Repository']}:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name']) ) {
				$selected = true;
				$name = $testDocker['Name'];
				break;
			}
		}
	} else
		$pluginName = basename($template['PluginURL']);

	if ( $template['trending'] ) {
		$allApps = readJsonFile($caPaths['community-templates-info']);

		$allTrends = array_unique(array_column($allApps,"trending"));
		rsort($allTrends);
		$trendRank = array_search($template['trending'],$allTrends) + 1;
	}
	$template['Category'] = categoryList($template['Category'],true);
	$template['Icon'] = $template['Icon'] ? $template['Icon'] : "/plugins/dynamix.docker.manager/images/question.png";
	if ( $template['Overview'] )
		$ovr = $template['OriginalOverview'] ?: $template['Overview'];
	if ( ! $ovr )
		$ovr = $template['OriginalDescription'] ?: $template['Description'];
	$ovr = html_entity_decode($ovr);
	$ovr = str_replace(["[","]"],["<",">"],$ovr);
	$ovr = str_replace("\n","<br>",$ovr);
	$ovr = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$ovr);
	$ovr = markdown(strip_tags($ovr,"<br>"));
	$template['display_ovr'] = $ovr;

	$template['ModeratorComment'] .= $template['CAComment'];

	if ( $template['Plugin'] ) {
		$templateURL = $template['PluginURL'];
		download_url($templateURL,$caPaths['pluginTempDownload']);
		$template['Changes'] = @plugin("changes",$caPaths['pluginTempDownload']);

		$template['pluginVersion'] = @plugin("version",$caPaths['pluginTempDownload']) ?: $template['pluginVersion'];

	} else {
		if ( ! $template['Changes'] && $template['ChangeLogPresent']) {
			$templateURL = $template['caTemplateURL'] ?: $template['TemplateURL'];
			download_url($templateURL,$caPaths['pluginTempDownload']);
			$xml = readXmlFile($caPaths['pluginTempDownload']);
			$template['Changes'] = $xml['Changes'];
		}
	}
	$template['Changes'] = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$template['Changes']); // Prevent inadvertent code blocks
	$template['Changes'] = Markdown(strip_tags(str_replace(["[","]"],["<",">"],$template['Changes']),"<br>"));
	if ( trim($template['Changes']) )
		$template['display_changes'] = trim($template['Changes']);

	if ( $template['IconFA'] ) {
		$template['IconFA'] = $template['IconFA'] ?: $template['Icon'];
		$templateIcon = startsWith($template['IconFA'],"icon-") ? "{$template['IconFA']} unraidIcon" : "fa fa-{$template['IconFA']}";
		$template['display_icon'] = "<i class='$templateIcon popupIcon'></i>";
	} else
		$template['display_icon'] = "<img class='popupIcon' src='{$template['Icon']}' onerror='this.src=&quot;/plugins/dynamix.docker.manager/images/question.png&quot;' alt='Application Icon'>";

	if ( $template['Requires'] ) {
		$template['Requires'] = Markdown(strip_tags(str_replace(["\r","\n","&#xD;"],["","<br>",""],trim($template['Requires'])),"<br>"));
		preg_match_all("/\/\/(.*?)&#92;/m",$template['Requires'],$searchMatches);
		if ( count($searchMatches[1]) ) {
			foreach ($searchMatches[1] as $searchResult) {
				$template['Requires'] = str_replace("//$searchResult&#92;","<a style='cursor:pointer;' onclick='doSidebarSearch(&quot;$searchResult&quot;);'>$searchResult</a>",$template['Requires']);
			}
		}
	}
	$actionsContext = [];
	if ( ! $template['Language'] ) {
		if ( ! $template['NoInstall'] && ! $caSettings['NoInstalls']) {
			if ( ! $template['Plugin'] ) {
				if ( $caSettings['dockerRunning'] ) {
					if ( $selected ) {
						if ( $info[$name]['url'] && $info[$name]['running'] ) {
							$actionsContext[] = array("icon"=>"ca_fa-globe","text"=>"WebUI","action"=>"openNewWindow('{$info[$name]['url']}','_blank');");
						}
						$tmpRepo = strpos($template['Repository'],":") ? $template['Repository'] : $template['Repository'].":latest";
						$tmpRepo = strpos($tmpRepo,"/") ? $tmpRepo : "library/$tmpRepo";
						if ( $dockerUpdateStatus[$tmpRepo]['status'] == "false" ) {
							$template['UpdateAvailable'] = true;
							$actionsContext[] = array("icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"updateDocker('$name');");
						} else {
							$template['UpdateAvailable'] = false;
						}
						if ( $caSettings['defaultReinstall'] == "true" && ! $template['Blacklist'] && $template['ID'] !== false) {
							if ( $template['BranchID'] )
								$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"displayTags('{$template['ID']}',true,'','".portsUsed($template)."');");
							else
								$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','second','','".portsUsed($template)."');");
						}
						$actionsContext[] = array("icon"=>"ca_fa-edit","text"=>tr("Edit"),"action"=>"popupInstallXML('".addslashes($info[$name]['template'])."','edit');");
						$actionsContext[] = array("divider"=>true);
						if ( $info[$name]['template'] )
							$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallDocker('".addslashes($info[$name]['template'])."','{$template['Name']}');");

					} elseif ( ! $template['Blacklist'] ) {
						if ( $template['InstallPath'] ) {
							$userTemplate = readXmlFile($template['InstallPath'],false,false);
							if ( ! $template['Blacklist'] ) {
								$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Reinstall"),"action"=>"popupInstallXML('".addslashes($template['InstallPath'])."','user','','".portsUsed($userTemplate)."');");
								$actionsContext[] = array("divider"=>true);
							}
							$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','{$template['Name']}');");
						}	else {
							if ( ! $template['Blacklist'] ) {
								if ( ( $template['Compatible'] || $caSettings['hideIncompatible'] !== "true" )  ) {
									if ( !$template['Deprecated'] || $caSettings['hideDeprecated'] !== "true" ) {
										if ( ! $template['BranchID'] ) {
											$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','default','','".portsUsed($template)."');");
										} else {
											$actionsContext[] = array("icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"displayTags('{$template['ID']}',false,'','".portsUsed($template)."');");
										}
									}
								}
							}
						}
					}
				}
			} else {
				if ( file_exists("/var/log/plugins/$pluginName") ) {
					$template['installedVersion'] = plugin("version","/var/log/plugins/$pluginName");
					if ( $template['installedVersion'] != $template['pluginVersion'] && $template['Name'] !== "Community Applications") {
						@copy($caPaths['pluginTempDownload'],"/tmp/plugins/$pluginName");
						$template['UpdateAvailable'] = true;
						$actionsContext[] = array("icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"installPlugin('$pluginName',true);");
					} else {
						$template['UpdateAvailable'] = false;
					}
					$pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : plugin("launch","/var/log/plugins/$pluginName");
					if ( $pluginSettings ) {
						$actionsContext[] = array("icon"=>"ca_fa-pluginSettings","text"=>tr("Settings"),"action"=>"openNewWindow('/Apps/$pluginSettings');");
					}
					if ( $pluginName != "community.applications.plg" ) {
						if ( ! empty($actionsContext) )
							$actionsContext[] = array("divider"=>true);

						$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ","&nbsp;",$template['Name'])."');");
					}
				} elseif ( ! $template['Blacklist']  ) {
					if ( $template['Compatible'] || $caSettings['hideIncompatible'] !== "true") {
						if ( !$template['Deprecated'] || $caSettings['hideDeprecated'] !== "true" ) {
							if ( ($template['RequiresFile'] && is_file($template['RequiresFile']) ) || ! $template['RequiresFile'] ) {
								$buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
								$actionsContext[] = array("icon"=>"ca_fa-install","text"=>$buttonTitle,"action"=>"installPlugin('{$template['PluginURL']}');");
							}
						}
					}
					if ( $template['InstallPath'] ) {
						if ( ! empty($actionsContext) )
							$actionsContext[] = array("divider"=>true);
						$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','$pluginName');");
					}

				}
			}
		}
	}
	if ( $template['Language'] ) {
		$dynamixSettings = parse_ini_file($caPaths['dynamixSettings'],true);
		$currentLanguage = $dynamixSettings['display']['locale'] ?: "en_US";
		$installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"),array(".",".."));
		$installedLanguages = array_filter($installedLanguages,function($v) {
			return is_dir("/usr/local/emhttp/languages/$v");
		});
		$installedLanguages[] = "en_US";
		$currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage") ) ? $currentLanguage : "en_US";
		if ( in_array($countryCode,$installedLanguages) ) {
			if ( $currentLanguage != $countryCode ) {
				$actionsContext[] = array("icon"=>"ca_fa-switchto","text"=>$template['SwitchLanguage'],"action"=>"CAswitchLanguage('$countryCode');");
			}
		} else {
			$actionsContext[] = array("icon"=>"ca_fa-install","text"=>$template['InstallLanguage'],"action"=>"installLanguage('{$template['TemplateURL']}','$countryCode');");
		}
		if ( file_exists("/var/log/plugins/lang-$countryCode.xml") ) {
			if ( languageCheck($template) ) {
				$template['UpdateAvailable'] = true;
				$actionsContext[] = array("icon"=>"ca_fa-update","text"=>$template['UpdateLanguage'],"action"=>"updateLanguage('$countryCode');");
			}
			if ( $currentLanguage != $countryCode ) {
				if ( ! empty($actionsContext) )
					$actionsContext[] = array("divider"=>true);
				$actionsContext[] = array("icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove Language Pack")."</span>","action"=>"removeLanguage('$countryCode');");
			}
		}
		if ( $countryCode !== "en_US" ) {
			$template['Changes'] = "<center><a href='https://github.com/unraid/lang-$countryCode/commits/master' target='_blank'>".tr("Click here to view the language changelog")."</a></center>";
		} else {
			unset($template['Changes']);
		}
	}

	$supportContext = array();
	if ( $template['ReadMe'] )
		$supportContext[] = array("icon"=>"ca_fa-readme","link"=>$template['ReadMe'],"text"=>tr("Read Me First"));
	if ( $template['Project'] )
		$supportContext[] = array("icon"=>"ca_fa-project","link"=>$template['Project'],"text"=> tr("Project"));

	if ( $template['Discord'] )
		$supportContext[] = array("icon"=>"ca_discord","link"=>$template['Discord'],"text"=>tr("Discord"));
	elseif ( $allRepositories[$template['Repo']]['Discord'] )
		$supportContext[] = array("icon"=>"ca_discord","link"=>$allRepositories[$template['Repo']]['Discord'],"text"=>tr("Discord"));

	if ( $template['Facebook'] )
		$supportContext[] = array("icon"=>"ca_facebook","link"=>$template['Facebook'],"text"=>tr("Facebook"));
	if ( $template['Reddit'] )
		$supportContext[] = array("icon"=>"ca_reddit","link"=>$template['Reddit'],"text"=>tr("Reddit"));

	if ( $template['Support'] )
		$supportContext[] = array("icon"=>"ca_fa-support","link"=>$template['Support'],"text"=> $template['SupportLanguage'] ?: tr("Support Forum"));

	if ( $template['Registry'] )
		$supportContext[] = array("icon"=>"ca_fa-docker","link"=>$template['Registry'],"text"=> tr("Registry"));
	if ( $caSettings['dev'] == "yes" )
		$supportContext[] = array("icon"=>"ca_fa-template","link"=> $template['caTemplateURL'] ?: $template['TemplateURL'],"text"=>tr("Application Template"));

	$author = $template['PluginURL'] ? $template['PluginAuthor'] : $template['SortAuthor'];

	if (is_array($template['trends']) && (count($template['trends']) > 1) ){
		if ( $template['downloadtrend'] ) {
			$templateDescription .= "<div><canvas id='trendChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
			$templateDescription .= "<div><canvas id='downloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
			$templateDescription .= "<div><canvas id='totalDownloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
		}
	}
	if ( ! $countryCode ) {
		$changeLogMessage = "Note: not all ";
		$changeLogMessage .= $template['PluginURL'] || $template['Language'] ? "authors" : "maintainers";
		$changeLogMessage .= " keep up to date on change logs<br>";
		$template['display_changelogMessage'] = tr($changeLogMessage);
	}

	if (is_array($template['trendsDate']) ) {
		array_walk($template['trendsDate'],function(&$entry) {
			$entry = tr(date("M",$entry),0).date(" j",$entry);
		});
	}

	if ( is_array($template['trends']) ) {
		if ( count($template['trends']) < count($template['downloadtrend']) )
			array_shift($template['downloadtrend']);

		$chartLabel = $template['trendsDate'];
		if ( is_array($template['downloadtrend']) ) {
			#get what the previous download value would have been based upon the trend
			$minDownload = intval(  ((100 - $template['trends'][0]) / 100)  * ($template['downloadtrend'][0]) );
			foreach ($template['downloadtrend'] as $download) {
				$totalDown[] = $download;
				$down[] = intval($download - $minDownload);
				$minDownload = $download;
			}
			$downloadLabel = $template['trendsDate'];
		}
		$down = is_array($down) ? $down : array();
	}

	if ( $pinnedApps["{$template['Repository']}&{$template['SortName']}"] ) {
		$template['pinned'] = tr("Unpin App");
		$template['pinnedTitle'] = tr("Click to unpin this application");
		$template['pinnedClass'] = "pinned";
	} else {
		$template['pinned'] = tr("Pin App");
		$template['pinnedTitle'] = tr("Click to pin this application");
		$template['pinnedClass'] = "unpinned";
	}
	$template['actionsContext'] = $actionsContext;
	$template['supportContext'] = $supportContext;
	@unlink($caPaths['pluginTempDownload']);

	return array("description"=>displayPopup($template),"trendData"=>$template['trends'],"trendLabel"=>$chartLabel,"downloadtrend"=>$down,"downloadLabel"=>$downloadLabel,"totaldown"=>$totalDown,"totaldownLabel"=>$downloadLabel,"supportContext"=>$supportContext,"actionsContext"=>$actionsContext,"ID"=>$template['ID']);
}

#####################################
# Generate the display for the repo #
#####################################
function getRepoDescriptionSkin($repository) {
	global $caSettings, $caPaths, $language;

	$dockerVars = parse_ini_file($caPaths['docker_cfg']);
	$repositories = readJsonFile($caPaths['repositoryList']);
	$templates = readJsonFile($caPaths['community-templates-info']);
	$repo = $repositories[$repository];
	$repo['icon'] = $repo['icon'] ?: "/plugins/dynamix.docker.manager/images/question.png";
	$repo['bio'] = $repo['bio'] ? markdown($repo['bio']) : "<br><center>".tr("No description present");
	$favRepoClass = ($caSettings['favourite'] == $repository) ? "fav" : "nonfav";

	$totalApps = $totalPlugins = $totalDocker = $totalDownloads = 0;

	foreach ($templates as $template) {
		if ( $template['RepoName'] !== $repository ) continue;
		if ( $template['BranchID'] ) continue;

		if ( $template['Blacklist'] ) continue;
		if ( $template['Deprecated'] && $caSettings['hideDeprecated'] !== "false" ) continue;
		if ( ! $template['Compatible'] && $caSettings['hideIncompatible'] !== "false" ) continue;

		if ( $template['Registry'] ) {
			$totalDocker++;
			if ( $template['downloads'] ) {
				$totalDownloads = $totalDownloads + $template['downloads'];
				$downloadDockerCount++;
			}
		}
		if ( $template['PluginURL'] ) {
			$totalPlugins++;
		}
		if ( $template['Language'] ) {
			$totalLanguage++;
		}

		$totalApps++;
	}

	$t .= "
		<div class='popUpClose'>".tr("CLOSE")."</div>
		<div class='popUpBack'>".tr("BACK")."</div>
		<div class='ca_popupIconArea'>
			<div class='popupIcon'>
				<img class='popupIcon' src='{$repo['icon']}' onerror='this.src=&quot;/plugins/dynamix.docker.manager/images/question.png&quot;'>
			</div>
			<div class='popupInfo'>
				<div class='popupName'>$repository</div>
				<div class='ca_repoSearchPopUp popupProfile' data-repository='".htmlentities($repository,ENT_QUOTES)."'>".tr("See All Apps")."</div>
				<div class='ca_favouriteRepo $favRepoClass' data-repository='".htmlentities($repository,ENT_QUOTES)."'>".tr("Favourite")."</div>
			</div>
		</div>
		<div class='popupRepoDescription'><br>".strip_tags($repo['bio'])."</div>
	";
	if ( $repo['DonateLink'] ) {
		$t .= "
			<div class='donateArea'>
				<div class='repoDonateText'>{$repo['DonateText']}</div>
				<a class='donate' href='{$repo['DonateLink']}' target='_blank'>".tr("Donate")."</a>
			</div>
		";
	}
	if ( $repo['Photo'] || $repo['Video']) {
		$t .= "<div>";
		if ( $repo['Photo'] ) {
			$photos = is_array($repo['Photo']) ? $repo['Photo'] : [$repo['Photo']];

			foreach ($photos as $shot) {
				$t .= "<a class='screenshot' href='".trim($shot)."'><img class='screen' src='".trim($shot)."' onerror='this.style.display=&quot;none&quot;'></img></a>";
			}
		}
		if ( $repo['Video'] ) {
			$videos = is_array($repo['Video']) ? $repo['Video'] : [$repo['Video']];
			foreach ($videos as $vid) {
				$t .= "<a class='screenshot mfp-iframe' href='".trim($vid)."'><div class='vid ca_fa-film'></div></a>";
			}
		}

		$t .= "</div>";
	}
	$t .= "
		</div>
		<div class='repoLinks'>
	";

	$t .= "<div class='repoLinkArea'>";

	if ( $repo['WebPage'] )
		$t .= "<a class='appIconsPopUp ca_webpage' href='{$repo['WebPage']}' target='_blank'> ".tr("Web Page")."</a>";
	if ( $repo['Forum'] )
		$t .= "<a class='appIconsPopUp ca_forum' href='{$repo['Forum']}' target='_blank'> ".tr("Forum")."</a>";
	if ( $repo['profile'] )
		$t .= "<a class='appIconsPopUp ca_profile' href='{$repo['profile']}' target='_blank'> ".tr("Forum Profile")."</a>";
	if ( $repo['Facebook'] )
		$t .= "<a class='appIconsPopUp ca_facebook' href='{$repo['Facebook']}' target='_blank'> ".tr("Facebook")."</a>";
	if ( $repo['Reddit'] )
		$t .= "<a class='appIconsPopUp ca_reddit' href='{$repo['Reddit']}' target='_blank'> ".tr("Reddit")."</a>";
	if ( $repo['Twitter'] )
		$t .= "<a class='appIconsPopUp ca_twitter' href='{$repo['Twitter']}' target='_blank'> ".tr("Twitter")."</a>";
	if ( $repo['Discord'] )
		$t .= "<a class='appIconsPopUp ca_discord_popup' target='_blank' href='{$repo['Discord']}' target='_blank'> ".tr("Discord")."</a>";

	$t .= "
		</div>
	  <div class='repoStats'>Statistics</div>
			<table class='repoTable'>
	";
	if ( $repo['FirstSeen'] > 1 )
		$t .= "<tr><td class='repoLeft'>".tr("Added to CA")."</td><td class='repoRight'>".date("F j, Y",$repo['FirstSeen'])."</td></tr>";

	$t .= "
				<tr><td class='repoLeft'>".tr("Total Docker Applications")."</td><td class='repoRight'>$totalDocker</td></tr>
				<tr><td class='repoLeft'>".tr("Total Plugin Applications")."</td><td class='repoRight'>$totalPlugins</td></tr>
		";
		if ( $totalLanguage )
			$t .= "
				<tr><td class='repoLeft''>".tr("Total Languages")."</td><td class='repoRight'>$totalLanguage</td></tr>
			";
	if ( $caSettings['dev'] == "yes" && $repo['url'])
		$t .= "
				<tr><td class='repoLeft'><a class='popUpLink' href='{$repo['url']}' target='_blank'>".tr("Repository URL")."</a></td></tr>
		";

	$t .= "
				<tr><td class='repoLeft'>".tr("Total Applications")."</td><td class='repoRight'>$totalApps</td></tr>
			";

	if ( $downloadDockerCount && $totalDownloads ) {
		$avgDownloads = intval($totalDownloads / $downloadDockerCount);
		$t .= "<tr><td class='repoLeft'>".tr("Total Known Downloads")."</td><td class='repoRight'>".number_format($totalDownloads)."</td></tr>";
		$t .= "<tr><td class='repoLeft'>".tr("Average Downloads Per App")."</td><td class='repoRight'>".number_format($avgDownloads)."</td></tr>";
	}
	$t .= "</table>";
	$t .= "</div>";

	$t = "<div class='popup'>$t</div>";
	return array("description"=>$t);
}

########################################################################################
# function used to display the navigation (page up/down buttons) for dockerHub results #
########################################################################################
function dockerNavigate($num_pages, $pageNumber) {
	return getPageNavigation($pageNumber,$num_pages * 25, true);
}

##############################################################
# function that actually displays the results from dockerHub #
##############################################################
function displaySearchResults($pageNumber) {
	global $caPaths, $caSettings, $plugin;

	$tempFile = readJsonFile($caPaths['dockerSearchResults']);
	$num_pages = $tempFile['num_pages'];
	$file = $tempFile['results'];
	$templates = readJsonFile($caPaths['community-templates-info']);

	$ct = "<div>".tr("NOTE You must visit the dockerHub page to gather the information required to install correctly")."<span class='templateSearch' style='float:right'>Show CA templates</span></div><br><br>";
	$ct .= "<div class='ca_templatesDisplay'>";

	$columnNumber = 0;
	foreach ($file as $result) {
		$result['Icon'] = "/plugins/dynamix.docker.manager/images/question.png";
		$result['display_dockerName'] = "<a class='ca_tooltip ca_applicationName' style='cursor:pointer;' onclick='mySearch(this.innerText);' title='".tr("Search for similar containers")."'>{$result['Name']}</a>";
		$result['Category'] = "Docker&nbsp;Hub&nbsp;Search";
		$result['Description'] = $result['Description'] ?: tr("No description present");
		$result['Compatible'] = true;
		$result['actionsContext'] = [["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"dockerConvert({$result['ID']});"]];

		$templateSearch = searchArray($templates,"Repository",$result['Repository']);
		if ( $templateSearch === false )
			$templateSearch = searchArray($templates,"Repository","{$result['Repository']}.latest");

		if ( $templateSearch !== false && ! $templates[$templateSearch]['Deprecated'] && ! $templates[$templateSearch]['Blacklist']) {
			$result['caTemplateExists'] = true;
			$result['Icon'] = $templates[$templateSearch]['Icon'];
			$result['Description'] = $templates[$templateSearch]['Overview'] ?: $templates[$templateSearch]['Description'];
			unset($result['IconFA']);
			$result['ID'] = $templates[$templateSearch]['ID'];
			$result['actionsContext'] = [["icon"=>"ca_fa-template","text"=>tr("Show Template"),"action"=>"doSearch(false,'{$templates[$templateSearch]['Repository']}');"]];
		}
		$ct .= displayCard($result);
		$count++;
	}
	$ct .= "</div>";

	return $ct.dockerNavigate($num_pages,$pageNumber);
}
###########################
# Generate the app's card #
###########################
function displayCard($template) {
	global $caSettings, $caPaths;
	$appName = str_replace("-"," ",$template['display_dockerName']);

	$popupType = $template['RepositoryTemplate'] ? "ca_repoPopup" : "ca_appPopup";
	if ( $template['DockerHub'] )
		unset($popupType);

	if ($template['Language']) {
		$language = "{$template['Language']}";
		$language .= $template['LanguageLocal'] ? " - {$template['LanguageLocal']}" : "";
		$template['Category'] = "";
	}

	extract($template);

	$class = "spotlightHome";

	$appType = $Plugin ? "appPlugin" : "appDocker";
	$appType = $Language ? "appLanguage": $appType;
	$appType = (strpos($Category,"Drivers") !== false) && $Plugin ? "appDriver" : $appType;
	$appType = $RepositoryTemplate ? "appRepository" : $appType;
	switch ($appType) {
		case "appPlugin":
			$typeTitle = tr("This application is a plugin");
			break;
		case "appDocker":
			$typeTitle = tr("This application is a docker container");
			break;
		case "appLanguage":
			$typeTitle = tr("This is a language pack");
			break;
		case "appDriver":
			$typeTitle = tr("This application is a driver (plugin)");
			break;
	}
	if ($InstallPath)
		$Path = $InstallPath;

	$Category = explode(" ",$Category)[0];
	$Category = explode(":",$Category)[0];

	if ( ! $DockerHub )
		$author = $RepoShort ?: $RepoName;
	else
		$author = $Author;

	if ( $Plugin )
		$author = $Author;
	if ( $Language )
		$author = "Unraid";

	if ( !$RepositoryTemplate ) {
		$cardClass = "ca_appPopup";
		$supportContext = array();
		if ( $ReadMe )
			$supportContext[] = array("icon"=>"ca_fa-readme","link"=>$ReadMe,"text"=>tr("Read Me First"));
		if ( $Project )
			$supportContext[] = array("icon"=>"ca_fa-project","link"=>$Project,"text"=> tr("Project"));
		if ( $Discord )
			$supportContext[] = array("icon"=>"ca_discord","link"=>$Discord,"text"=>tr("Discord"));
		if ( $Support )
			$supportContext[] = array("icon"=>"ca_fa-support","link"=>$Support,"text"=> $SupportLanguage ?: tr("Support Forum"));

	} else {
		$holderClass='repositoryCard';
		$cardClass = "ca_repoinfo";
		$ID = str_replace(" ","",$RepoName);
		$supportContext = array();
		if ( $profile )
			$supportContext[] = array("icon"=>"ca_profile","link"=>$profile,"text"=>tr("Profile"));
		if ( $Forum )
			$supportContext[] = array("icon"=>"ca_forum","link"=>$Forum,"text"=>tr("Forum"));
		if ( $Twitter )
			$supportContext[] = array("icon"=>"ca_twitter","link"=>$Twitter,"text"=>tr("Twitter"));
		if ( $Reddit )
			$supportContext[] = array("icon"=>"ca_reddit","link"=>$Reddit,"text"=>tr("Reddit"));
		if ( $Facebook )
			$supportContext[] = array("icon"=>"ca_facebook","link"=>$Facebook,"text"=>tr("Facebook"));
		if ( $WebPage )
			$supportContext[] = array("icon"=>"ca_webpage","link"=>$WebPage,"text"=>tr("Web Page"));

		$Name = str_replace("' Repository","",str_replace("'s Repository","",$author));
		$Name = str_replace(" Repository","",$Name);
		$author = "";
	}

	$display_repoName = str_replace("' Repository","",str_replace("'s Repository","",$display_repoName));

	$bottomClass = "ca_bottomLineSpotLight";
	if ( $DockerHub ) {
		$backgroundClickable = "dockerCardBackground";
		$card .= "
			<div class='dockerHubHolder $class $popupType'>
			<div class='ca_bottomLine $bottomClass'>
			<div class='infoButton_docker dockerPopup' data-dockerHub='$DockerHub'>".tr("Docker Hub")."</div>";
	} else {
		$backgroundClickable = "ca_backgroundClickable";
		$card .= "
			<div class='ca_holder $class $popupType $holderClass' data-apppath='$Path' data-appname='$Name' data-repository='".htmlentities($RepoName,ENT_QUOTES)."'>
			<div class='ca_bottomLine $bottomClass'>
			<div class='infoButton $cardClass'>".tr("Info")."</div>
		";
	}
	if ( count($supportContext) == 1)
		$card .= "<div class='supportButton'><span class='ca_href' data-href='{$supportContext[0]['link']}' data-target='_blank'>{$supportContext[0]['text']}</span></div>";
	elseif (!empty($supportContext))
		$card .= "
			<div class='supportButton supportButtonCardContext' id='support".preg_replace("/[^a-zA-Z0-9]+/", "",$Name)."$ID' data-context='".json_encode($supportContext)."'>".tr("Support")."</div>
		";

	if ( $actionsContext ) {
		if ( count($actionsContext) == 1) {
			$dispText = $actionsContext[0]['alternate'] ?: $actionsContext[0]['text'];
			$card .= "<div class='actionsButton' onclick={$actionsContext[0]['action']}>$dispText</div>";
		}
		else
			$card .= "<div class='actionsButton actionsButtonContext' id='actions".preg_replace("/[^a-zA-Z0-9]+/", "",$Name)."$ID' data-context='".json_encode($actionsContext,JSON_HEX_QUOT | JSON_HEX_APOS)."'>".tr("Actions")."</div>";
	}

	$card .= "<span class='$appType' title='".htmlentities($typeTitle)."'></span>";
	if ( $ca_fav ) {
		$favText = $RepositoryTemplate ? tr("This is your favourite repository") : tr("This application is from your favourite repository");
		$card .= "<span class='favCardBackground' title='".htmlentities($favText)."'></span>";
	}	else
		$card .= "<span class='favCardBackground' title='".htmlentities($favText)."' style='display:none;'></span>";
	if ( ! $Pinned )
		$pinStyle = "display:none;";

	$pindata = (strpos($Repository,"/") !== false) ? $Repository : "library/$Repository";
	$card .= "<span class='pinnedCard' title='".htmlentities(tr("This application is pinned for later viewing"))."' data-pindata='$pindata$SortName' style='$pinStyle'></span>";


	if ($Removable && !$DockerInfo  && ! $Installed && ! $Blacklist) {
		$previousAppName = $Plugin ? $PluginURL : $Name;
		$type = ($appType == "appDocker") ? "docker" : "plugin";
		$card .= "<input class='ca_multiselect ca_tooltip' title='".tr("Check off to select multiple reinstalls")."' type='checkbox' data-name='$previousAppName' data-humanName='$Name' data-type='$type' data-deletepath='$InstallPath' $checked>";
	}
	$card .= "</div>";
	$card .= "<div class='$cardClass $backgroundClickable'>";
	$card .= "<div class='ca_iconArea'>";
	if ( $DockerHub )
		$imageNoClick = "noClick";

	if ( ! $IconFA )
		$card .= "
			<img class='ca_displayIcon $imageNoClick' src='$Icon' alt='Application Icon'></img>
		";
	else {
		$displayIcon = $template['IconFA'] ?: $template['Icon'];
		$displayIconClass = startsWith($displayIcon,"icon-") ? $displayIcon : "fa fa-$displayIcon";
		$card  .= "<i class='ca_appPopup $displayIconClass displayIcon $imageNoClick'></i>";
	}
	$card .= "</div>";


	$card .= "
		<div class='ca_applicationName'>$Name
	";
	if ( $CAComment || $ModeratorComment || $Requires) {
		if ( $CAComment || $ModeratorComment) {
			$commentIcon = "ca_fa-comment";
			$warning = tr("Click info to see the notes regarding this application");
		}
		if ( $Requires ) {
			if ( $RequiresFile && ! is_file($RequiresFile) ) {
				$commentIcon = "ca_fa-additional";
				$warning = tr("This application has additional requirements");
			}
		}

		$card .= "&nbsp;<span class='$commentIcon cardWarning' title='".htmlentities($warning,ENT_QUOTES)."'></span>";
	}
	$card .= "
				</div>
				<div class='ca_author'>$author</div>
				<div class='cardCategory'>$Category</div>
	";

	$card .= "
		</div>
		";

	$Overview = $Overview ?: $Description;

	$ovr = html_entity_decode($Overview);
	$ovr = trim($ovr);
	$ovr = str_replace(["[","]"],["<",">"],$ovr);
	$ovr = str_replace("\n","<br>",$ovr);

//	$ovr = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$ovr);
	$ovr = markdown(strip_tags($ovr,"<br>"));

	$ovr = str_replace("\n","<br>",$ovr);
	$Overview = strip_tags(str_replace("<br>"," ",$ovr));

	$descClass= $RepositoryTemplate ? "cardDescriptionRepo" : "cardDescription";
	$card .= "<div class='$descClass $backgroundClickable'><div class='cardDesc'>$Overview</div></div>";
	if ( $RecommendedDate ) {
		$card .= "
			<div class='homespotlightIconArea ca_center''>
				<div><img class='spotlightIcon' src='{$caPaths['SpotlightIcon']}' alt='Spotlight'></img></div>
				<div class='spotlightDate'>".tr(date("M Y",$RecommendedDate),0)."</div>
			</div>
		";
	}
	$card .= "</div>";
	if ( $Installed || $Uninstall ) {
		$flagTextStart = tr("Installed")."<br>";
	} else {
		$flagTextStart = "&nbsp;";
		$flagTextEnd = "&nbsp;";
	}
	if ( $Blacklist ) {
		$card .= "
			<div class='warningCardBackground'>
				<div class='betaPopupText ca_center' title='".tr("This application template / has been blacklisted")."'>".tr("Blacklisted")."$flagTextEnd</div>
			</div>
		";
	} elseif ( $caTemplateExists ) {
		$card .= "
			<div class='warningCardBackground'>
				<div class='betaPopupText ca_center' title='".tr("Template already exists in Apps")."'>".tr("Template Exists")."</div>
			</div>
		";
	} elseif ( isset($Compatible) && ! $Compatible ) {
		$verMsg = $VerMessage ?: tr("This application is not compatible with your version of Unraid");
		$card .= "
			<div class='warningCardBackground'>
				<div class='betaPopupText ca_center' title='$verMsg'>$flagTextStart".tr("Incompatible")."$flagTextEnd</div>
			</div>
		";
	} elseif ( $Deprecated ) {
		$card .= "
			<div class='warningCardBackground'>
				<div class='betaPopupText ca_center' title='".tr("This application template has been deprecated")."'>".tr("Deprecated")."$flagTextEnd</div>
			</div>
		";
	} elseif ( $UpdateAvailable ) {	
		$card .= "
			<div class='betaCardBackground'>
				<div class='installedCardText ca_center'>".tr("UPDATED")."</div>
			</div>";
	} elseif ( $Installed || $Uninstall) {
		 $card .= "
			 <div class='installedCardBackground'>
			   <div class='installedCardText ca_center'>".tr("INSTALLED")."</div>
			</div>";
	} elseif ( $Official ) {
		$card .= "
			<div class='officialCardBackground'>
				<div class='officialPopupText ca_center' title='This is an official container'>".tr("OFFICIAL")."</div>
			</div>
		";
	} elseif ( $Beta ) {
		$card .= "
			<div class='betaCardBackground'>
				<div class='betaPopupText ca_center'>".tr("BETA")."</div>
			</div>
		";
	} elseif ( $RecommendedDate ) {
		$card .= "
			<div class='spotlightCardBackground'>
				<div class='spotlightPopupText' title='".tr("This is a spotlight application")."'></div>
			</div>
		";
	} elseif ( $Trusted ) {
		$card .= "
			<div class='spotlightCardBackground'>
				<div class='betaPopupText ca_center' title='".tr("This container is digitally signed")."'>".tr("Digitally Signed")."</div>
			</div>
		";
	}
	return str_replace(["\t","\n"],"",$card);
}

function displayPopup($template) {
	global $caSettings, $caPaths;

	extract($template);

	if ( !$Private)
		$RepoName = str_replace("' Repository","",str_replace("'s Repository","",$Repo));
	else {
		$RepoName = str_replace("' Repository","",str_replace("'s Repository","",$RepoName));
		$Repo = $RepoName;
	}
	if ( $RepoShort ) $RepoName = $RepoShort;

	$FirstSeen = ($FirstSeen < 1433649600 ) ? 1433000000 : $FirstSeen;
	$DateAdded = tr(date("M j, Y",$FirstSeen),0);
	$favRepoClass = ($caSettings['favourite'] == $Repo) ? "fav" : "nonfav";
	$card = "
		<div class='popup'>
		<div><span class='popUpClose'>".tr("CLOSE")."</span></div>
		<div class='ca_popupIconArea'>
			<div class='popupIcon'>$display_icon</div>
			<div class='popupInfo'>
				<div class='popupName'>$Name</div>
		";
		if ( ! $Language )
			$card .= "<div class='popupAuthorMain'>$Author</div>";

		if ( $actionsContext ) {
			if ( count($actionsContext) == 1 ) {
				$card .= "<div class='actionsPopup'><span onclick={$actionsContext[0]['action']}>".str_replace("ca_red","",$actionsContext[0]['text'])."</span></div>";
			} else {
				$card .= "
					<div class='actionsPopup' id='actionsPopup'>".tr("Actions")."</div>
				";
			}
		}

		if ( count($supportContext) == 1 )
			$card .= "<div class='supportPopup'><a href='{$supportContext[0]['link']}' target='_blank'><span class='{$supportContext[0]['icon']}'> {$supportContext[0]['text']}</span></a></div>";
		elseif ( count($supportContext) )
			$card.= "<div class='supportPopup' id='supportPopup'><span class='ca_fa-support'> ".tr("Support")."</div>";

		$card .= ($LanguagePack != "en_US" && ! $Blacklist && ! $NoPin) ? "<div class='pinPopup $pinnedClass' title='$pinnedTitle' data-repository='$Repository' data-name='$SortName'><span>$pinned</span></div>" : "";
		if ( ! $caSettings['dockerRunning'] && (! $Plugin && ! $Language) ) {
			$card .= "<div class='ca_red'>".tr("Docker Service Not Enabled - Only Plugins Available To Be Installed Or Managed")."</div>";
		}
		$card .= "
			</div>
		</div>
		<div class='popupDescription popup_readmore'>$display_ovr</div>
	";
	if ( $Requires && ! is_file($RequiresFile) )
		$card .= "<div class='additionalRequirementsHeader'>".tr("Additional Requirements")."</div><div class='additionalRequirements'>{$template['Requires']}</div>";

	if ( $Deprecated )
		$ModeratorComment .= "<br>".tr("This application template has been deprecated");
	if ( ! $Compatible && ! $UnknownCompatible )
		$ModeratorComment .= $VerMessage ?: "<br>".tr("This application is not compatible with your version of Unraid");
	if ( $Blacklist )
		$ModeratorComment .= "<br>".tr("This application template has been blacklisted");

	$ModeratorComment .= $caComment;
	if ( $Language && $LanguagePack !== "en_US" ) {
		$ModeratorComment .= "<a href='$disclaimLineLink' target='_blank'>$disclaimLine1</a>";
	}

	if ( $ModeratorComment ) {
		$card .= "<div class='modComment'><div class='moderatorCommentHeader'> ".tr("Attention:")."</div><div class='moderatorComment'>$ModeratorComment</div></div>";
	}

	if ( $RecommendedReason) {
		$RecommendedLanguage = $_SESSION['locale'] ?: "en_US";
		if ( ! $RecommendedReason[$RecommendedLanguage] )
			$RecommendedLanguage = "en_US";

		if ( ! $RecommendedWho ) $RecommendedWho = tr("Unraid Staff");
		$card .= "
			<div class='spotlightPopup'>
				<div class='spotlightIconArea ca_center'>
					<div><img class='spotlightIcon' src='{$caPaths['SpotlightIcon']}' alt='Spotlight'></img></div>
					<div class='spotlightDate'>".tr(date("M Y",$RecommendedDate),0)."</div>
				</div>
				<div class='spotlightInfoArea'>
					<div class='spotlightHeader'></div>
					<div class='spotlightWhy'>".tr("Why we picked it")."</div>
					<div class='spotlightMessage'>{$RecommendedReason[$RecommendedLanguage]}</div>
					<div class='spotlightWho'>- $RecommendedWho</div>
				</div>
			</div>
		";
	}
	if ( $Screenshot || $Photo || $Video) {
		if ( $Screenshot || $Photo ) {
			$pictures = $Screenshot ? $Screenshot : $Photo;
			if ( ! is_array($pictures) )
				$pictures = [$pictures];

			$card .= "<div>";
			foreach ($pictures as $shot) {
				$card .= "<a class='screenshot mfp-image' href='".trim($shot)."'><img class='screen' src='".trim($shot)."'></img></a>";
			}
			$card .= "</div>";
		}

		if ( $Video ) {
			if ( ! is_array($Video) )
				$Video = [$Video];

			$vidText = (count($Video) == 1) ? "Play Video" : "Play Video %s";
			$card .= "<div>";
			$count = 1;
			foreach ( $Video as $vid ) {
				$card .= "<a class='screenshot videoButton mfp-iframe' href='".trim($vid)."'><div class='ca_fa-film'> ".sprintf(tr($vidText),$count)."</div></a>";
				$count++;
			}
			$card .= "</div>";
		}
	}
	$appType = $Plugin ? tr("Plugin") : tr("Docker");
	$appType = $Language ? tr("Language") : $appType;

	$card .= "
		<div>
		<div class='popupInfoSection'>
			<div class='popupInfoLeft'>
			<div class='rightTitle'>".tr("Details")."</div>
			<table style='display:initial;'>
				<tr><td class='popupTableLeft'>".tr("Application Type")."</td><td class='popupTableRight'>$appType</td></tr>
				<tr><td class='popupTableLeft'>".tr("Categories")."</td><td class='popupTableRight'>$Category</td></tr>
				<tr><td class='popupTableLeft'>".tr("Added")."</td><td class='popupTableRight'>$DateAdded</td></tr>
	";
	$downloadText = getDownloads($downloads);
	if ($downloadText)
		$card .= "<tr><td class='popupTableLeft'>".tr("Downloads")."</td><td class='popupTableRight'>$downloadText</td></tr>";
	if (!$Plugin && !$LanguagePack)
		$card .= "<tr><td class='popupTableLeft'>".tr("Repository")."</td><td class='popupTableRight' style='white-space:nowrap;'>$Repository</td></tr>";
	if ($stars)
		$card .= "<tr><td class='popupTableLeft'>".tr("DockerHub Stars:")."</td><td class='popupTableRight'>$stars <span class='dockerHubStar'></span></td></tr>";
	if ( ! $Plugin && ! $Language ) {
		$tag = explode(":",$Repository)[1];
		if ( ! $tag || strtolower($tag) === "latest" ) {
			$lastUpdateMsg = $LastUpdate ? tr(date("M j, Y",$LastUpdate),0) : tr("Unknown");
			$card .= "<tr><td class='popupTableLeft'>".tr("Last Update:")."</td><td class='popupTableRight'><span id='template{$template['ID']}'>$lastUpdateMsg <span class='ca_note'><span class='ca_fa-asterisk'></span></span></span></td></tr>";
		}
	}
	if ( $Plugin && $installedVersion) {
		$card .= "<tr><td class='popupTableLeft'>".tr("Installed Version")."</td><td class='popupTableRight'>$installedVersion</td></tr>";
		if ( $installedVersion != $pluginVersion ) {
			$card .= "<tr><td class='popupTableLeft'>".tr("Upgrade Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
		}
	}
	if ( $Plugin && ! $installedVersion ) {
		$card .= "<tr><td calss='popupTableLeft'>".tr("Current Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
	}

	if ( $Plugin || ! $Compatible) {
		if ( $MinVer )
			$card .= "<tr><td class='popupTableLeft'>".tr("Min OS")."</td><td class='popupTableRight'>$MinVer</td></tr>";
		if ( $MaxVer )
			$card .= "<tr><td class='popupTableLeft'>".tr("Max OS")."</td><td class='popupTableRight'>$MaxVer</td></tr>";
	}
	$Licence = $Licence ?: $License;
	if ( $Licence )
		$card .= "<tr><td class='popupTableLeft'>".tr("Licence")."</td><td class='popupTableRight'>$Licence</td></tr>";

	$card .= "</table>";
	if ( $Repo || $Private ) {
		$card .= "
			</div>
			<div class='popupInfoRight'>
					<div class='popupAuthorTitle'>".($Plugin ? tr("Author") : tr("Maintainer"))."</div>
					<div><div class='popupAuthor'>".($Plugin ? $Author : $RepoName)."</div>
					<div class='popupAuthorIcon'><img class='popupAuthorIcon' src='$ProfileIcon' onerror='this.src=&quot;/plugins/dynamix.docker.manager/images/question.png&quot;' alt='Repository Icon'></img></div>
					</div>
					<div class='ca_repoSearchPopUp popupProfile' data-repository='".htmlentities($Repo,ENT_QUOTES)."'>".tr("All Apps")."</div>
					<div class='repoPopup' data-repository='".htmlentities($Repo,ENT_QUOTES)."'>".tr("Profile")."</div>
					<div class='ca_favouriteRepo $favRepoClass' data-repository='".htmlentities($Repo,ENT_QUOTES)."'>".tr("Favourite")."</div>
		";
	}

	if ( $DonateLink ) {
		$card .= "
			<div class='donateText'>$DonateText</div>
			<div class='donateDiv'><span class='donate'><a href='$DonateLink' target='_blank'>".tr("Donate")."</a></span></div>
		";
	}

	$card .= "
			</div>
		</div>
		</div>
	";

	if (is_array($trends) && (count($trends) > 1) ){
		if ( $downloadtrend ) {
			$card .= "
				<div class='charts chartTitle'>Trends</div>
				<div><span class='charts'>Show: <span class='chartMenu selectedMenu' data-chart='trendChart'>".tr("Trend Per Month")."</span><span class='chartMenu' data-chart='downloadChart'>".tr("Downloads Per Month")."</span><span class='chartMenu' data-chart='totalDownloadChart'>".tr("Total Downloads")."</span></div>
				<div>
				<div><canvas id='trendChart' class='caChart' height=1 width=3></canvas></div>
				<div><canvas id='downloadChart' class='caChart' style='display:none;' height=1 width=3</canvas></div>
				<div><canvas id='totalDownloadChart' class='caChart' style='display:none;' height=1 width=3></canvas></div>
				</div>
			";
		}
	}

	if ( $display_changes ) {
		$card .= "
			<div class='changelogTitle'>".tr("Change Log")."</div>
			<div class='changelogMessage'>$display_changelogMessage</div>
			<div class='changelog popup_readmore'>$display_changes</div>
		";
	}
	$moderation = readJsonFile($caPaths['statistics']);
	if ( $moderation['fixedTemplates'][$Repo][str_replace("library/","",$Repository)] ) {
		$card .= "<div class='templateErrors'>".tr("Template Errors")."</div>";
		foreach ($moderation['fixedTemplates'][$Repo][str_replace("library/","",$Repository)] as $error) {
			$card .= "<li class='templateErrorsList'>$error</li>";
		}
	}
	if ( ! $Plugin && ! $Language ){
		$card .= "<div><br><span class='ca_note ca_bold'><span class='ca_fa-asterisk'></span> ".tr("Note: All statistics are only gathered every 30 days")."</span></div>";
	}
	if ( $UpdateAvailable ) {
		$card .= "
			<div class='upgradePopupBackground'>
			<div class='upgradePopupText ca_center'>".tr("UPDATED")."</div></div>
		";
	} elseif ( $Beta ) {
		$card .= "
			<div class='betaPopupBackground'>
			<div class='betaPopupText ca_center'>".tr("BETA")."</div></div>
		";
	} elseif ( $RecommendedDate ) {
		$card .= "
			<div class='spotlightPopupBackground'>
			<div class='spotlightPopupText'></div>
		";
	}


	return $card;
}
?>
