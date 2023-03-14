<?php
/**
 *
 * Nextcloud - Notion
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Notion\Search;

use OCP\Search\IProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

use OCA\Notion\AppInfo\Application;
use OCA\Notion\Service\NotionAPIService;
use Psr\Log\LoggerInterface;

class NotionSearchDatabasesProvider implements IProvider {
	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var NotionAPIService
	 */
	private $service;
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								NotionAPIService $service,
								LoggerInterface $logger) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
		$this->logger = $logger;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'notion-search-databases';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Notion databases');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer Notion results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

		$accessToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token');
		$searchDatabasesEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_databases_enabled', '0') === '1';

		if ($accessToken === '' || !$searchDatabasesEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResult = $this->service->searchDatabases($user->getUID(), $term, $offset, $limit);
		if (isset($searchResult['error'])) {
			$databases = [];
		} else {
			$databases = $searchResult['results'];
		}

		$formattedResults = array_map(function (array $entry): NotionSearchResultEntry {
			return new NotionSearchResultEntry(
				$this->getThumbnailUrl($entry),
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToNotion($entry),
				'',
				false
			);
		}, $databases);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		return count($entry['title']) > 0 && isset($entry['title'][0]['plain_text'])
			? $entry['title'][0]['plain_text']
			: json_encode($entry['title']);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		return isset($entry['description']) && count($entry['description']) === 0
			? $entry['description'][0]['plain_text']
			: $entry['last_edited_time'];
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getLinkToNotion(array $entry): string {
		return $entry['url'] ?? '';
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry): string {
		// TODO: Rewrite to use some image from Notion API (if possible)
		// TODO: Use dark theme icon if dark theme is enabled
		$link = $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
		// TODO: Decide whether to support external images as thumbnail or not (because of CSP)
		// if (isset($entry['icon']['type']) && $entry['icon']['type'] == 'file') {
		// 	$link = $entry['icon']['url'];
		// }
		// if (isset($entry['icon']['type']) && $entry['icon']['type'] == 'external') {
		// 	$link = $entry['icon']['external']['url'];
		// }
		return $link;
	}
}
