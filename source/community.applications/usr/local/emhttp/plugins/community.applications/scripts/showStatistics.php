<?PHP
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
echo "<body bgcolor='white'>";
$repositories = json_decode(file_get_contents($communityPaths['Repositories']),true);

switch ($_GET['arg1']) {
	case 'Repository':
		foreach ($repositories as $repo) {
			$repos[$repo['name']] = $repo['url'];
		}
		ksort($repos,SORT_FLAG_CASE | SORT_NATURAL);
		echo "<tt><table>";
		foreach (array_keys($repos) as $repo) {
			echo "<tr><td><b>$repo</td><td><a href='{$repos[$repo]}' target='_blank'>{$repos[$repo]}</a></td></tr>";
		}
		echo "</table></tt>";
		break;
	case 'Invalid':
		$moderation = @file_get_contents($communityPaths['invalidXML_txt']);
		if ( ! $moderation ) {
			echo "<br><br><center><b>No invalid templates found</b></center>";
			return;
		}
		$moderation = str_replace(" ","&nbsp;",$moderation);
		$moderation = str_replace("\n","<br>",$moderation);
		echo "<tt>These templates are invalid and the application they are referring to is unknown<br><br>$moderation";
		break;
	case 'Fixed':
		$moderation = @file_get_contents($communityPaths['fixedTemplates_txt']);
				
		if ( ! $moderation ) {
			echo "<br><br><center><b>No templates were automatically fixed</b></center>";
		} else {
			$moderation = str_replace(" ","&nbsp;",$moderation);
			$moderation = str_replace("\n","<br>",$moderation);
			echo "All of these errors found have been fixed automatically.  These errors only affect the operation of Community Applications.  <b>The template <em>may</em> have other errors present</b><br><br><tt>$moderation";
		}

		$dupeList = json_decode(@file_get_contents($communityPaths['pluginDupes']),true);
		if ($dupeList) {
			$templates = json_decode(file_get_contents($communityPaths['community-templates-info']),true);
			echo "<br><br><b></tt>The following plugins have duplicated filenames and are not able to be installed simultaneously:</b><br><br>";
			foreach (array_keys($dupeList) as $dupe) {
				echo "<b>$dupe</b><br>";
				foreach ($templates as $template) {
					if ( basename($template['PluginURL']) == $dupe ) {
						echo "<tt>{$template['Author']} - {$template['Name']}<br></tt>";
					}
				}
				echo "<br>";
			}
		}
		$templates = json_decode(file_get_contents($communityPaths['community-templates-info']),true);
		foreach ($templates as $template) {
			$count = 0;
			foreach ($templates as $searchTemplates) {
				if ( ($template['Repository'] == $searchTemplates['Repository'])  ) {
					if ( $searchTemplates['BranchName'] || $searchTemplates['Blacklist'] ) {
						continue;
					}
					$count++;
				}
			}
			if ($count > 1) {
				$dupeRepos .= "Duplicated Template: {$template['RepoName']} - {$template['Repository']} - {$template['Name']}<br>";
			}
		}
		if ( $dupeRepos ) {
			echo "<br><b></tt>The following docker applications refer to the same docker repository, but may have subtle changes in the template to warrant this</b><br><br><tt>$dupeRepos";
		}

		break;
	case 'Moderation':
		$moderation = @file_get_contents($communityPaths['moderation']);
		foreach ($repositories as $repo) {
			if ($repo['RepoComment']) {
				$repoComment .= "<tr><td>{$repo['name']}</td><td>{$repo['RepoComment']}</td></tr>";
			}
		}
		if ( $repoComment ) {
			echo "<br><center><strong>Global Repository Comments:</strong><br>(Applied to all applications)</center><br><br><tt><table>$repoComment</table><br><br>";
		}
		if ( ! $moderation ) {
			echo "<br><br><center><b>No moderation entries found</b></center>";
		}
		echo "</tt><center><strong>Individual Application Moderation</strong></center><br><br>";
		$moderation = str_replace(" ","&nbsp;",$moderation);
		$moderation = str_replace("\n","<br>",$moderation);
		echo "<tt>$moderation";
		break;
}
?>