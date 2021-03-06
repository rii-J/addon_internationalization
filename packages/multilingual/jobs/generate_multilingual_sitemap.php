<?php defined('C5_EXECUTE') or die("Access Denied.");
/**
 * Responsible for loading the indexed search class and initiating the reindex command.
 * @package Utilities
 */
class GenerateMultilingualSitemap extends Job {

	public function getJobName() {
		return t('Generate multilingual sitemap.xml File');
	}


	public function getJobDescription() {
		return t("Generate the sitemap.xml file that search engines use to crawl your site with additional multilingual directives.");
	}

	function run() {
		if(!defined('ENABLE_MULTILINGUAL_SITEMAPXML') || !ENABLE_MULTILINGUAL_SITEMAPXML) {
			return t("This job is disabled because it may cause excessive load depending on the size of your site.  To enable this job, you must define the constant ENABLE_MULTILINGUAL_SITEMAPXML as true.");
		}

		$ni = Loader::helper('navigation');
		$tp = Loader::helper('translated_pages', 'multilingual');

		$xmlFile = DIR_BASE.'/sitemap.xml';
		$xmlHead = "<" . "?" . "xml version=\"1.0\" encoding=\"" . APP_CHARSET . "\"?>\n".
				   "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
		$home = '';
		$c = Page::getByID(1, "ACTIVE");
		$changefreq = $c->getAttribute('sitemap_changefreq');
		$priority = $c->getAttribute("sitemap_priority");

		if ($changefreq == '') {
			$changefreq = 'weekly';
		}
		if ($priority == '') {
			$priority = '1.0';
		}
		$home .= "<url>\n";
		$home .= "<loc>". BASE_URL.DIR_REL."</loc>\n";
		$home .= "  <lastmod> " . date('Y-m-d') . "</lastmod>\n";
		$home .= "  <changefreq>" . $changefreq . "</changefreq>\n";
		$home .= "  <priority>" . $priority . "</priority>\n";

		$translated_pages = $tp->getTranslatedPages($c);
		foreach($translated_pages as $locale => $page) {
			$home .= "  ".$tp->altMeta($locale,$page,'xhtml:link')."\n";
		}
		$home .= "</url>\n";
		$xmlFoot = "</urlset>\n";

		if (!file_exists($xmlFile)) { @touch($xmlFile); }

		if (is_writable($xmlFile)) {
			if (!$handle = fopen($xmlFile, 'w')) {
				throw new Exception(t("Cannot open file %s", $xmlFile));
			}
			
			$addedPages = 0;

			fwrite($handle, $xmlHead);
			fwrite($handle, $home);
			fflush($handle);
			$addedPages++;

			$db = Loader::db();
			$collection_attributes = Loader::model('collection_attributes');
			$r = $db->query("select cID from Pages where cID > 1 order by cID asc");
			$nh = Loader::helper('navigation');
			$dh = Loader::helper('concrete/dashboard');

			$g = Group::getByID(GUEST_GROUP_ID);
			$groupPermissionEntity = GroupPermissionAccessEntity::getOrCreate($g);

			while ($row = $r->fetchRow()) {
				$c = Page::getByID($row['cID'], 'ACTIVE');

				$g->setPermissionsForObject($c);

				if (($c->isSystemPage()) ||
					($c->getAttribute("exclude_sitemapxml")) ||
					($c->isExternalLink()) ||
					($dh->inDashboard($c))) {
					continue;
				}

				$gcanRead = false;
				do {
					if (method_exists($g, 'canRead')) {
						if ($g->canRead()) {
							$gcanRead = true;
						}
					} else {
						$pk = PermissionKey::getByHandle('view_page');
						$pk->setPermissionObject($c);
						$pa = $pk->getPermissionAccessObject();
						if(!is_object($pa)) {
							break;
						}
						$accessEntities[] = GroupPermissionAccessEntity::getOrCreate($g);
						if (!$pa->validateAccessEntities($accessEntities)) {
							break;
						}
					}
					$gcanRead = true;
				} while (false);
				if ($gcanRead) {

					$viewPageKey = PermissionKey::getByHandle('view_page');
					$viewPageKey->setPermissionObject($c);
					$pa = $viewPageKey->getPermissionAccessObject();

					if (is_object($pa) && $pa->validateAccessEntities(array($groupPermissionEntity))) {

						$name = ($c->getCollectionName()) ? $c->getCollectionName() : '(No name)';
						$cPath = $ni->getCollectionURL($c);
						$changefreq = $c->getAttribute('sitemap_changefreq');
						$priority = $c->getAttribute("sitemap_priority");
						if ($changefreq == '') {
							$changefreq = 'weekly';
						}
						if ($priority == '') {
							$priority = '0.' . round(rand(1, 5));
						}

						$node = "<url>\n";
						$node .= "<loc>" . $cPath . "</loc>\n";
						$node .= "  <lastmod>". substr($c->getCollectionDateLastModified(), 0, 10)."</lastmod>\n";
						$node .= "  <changefreq>".$changefreq."</changefreq>\n";
						$node .= "  <priority>".$priority."</priority>\n";

						$translated_pages = $tp->getTranslatedPages($c,'NONE');
						foreach($translated_pages as $locale => $page) {
							$node .= "  ".$tp->altMeta($locale,$page,'xhtml:link')."\n";
						}
					}
					$node .= "</url>\n";

					fwrite($handle, $node);
					fflush($handle);
					$addedPages++;
				}
			}

			fwrite($handle, $xmlFoot);
			fflush($handle);
			fclose($handle);

			return t("Sitemap XML File Saved.(%d pages)", $addedPages);

		} else {
			throw new Exception(t("The file %s is not writable", $xmlFile));
		}
	}

}