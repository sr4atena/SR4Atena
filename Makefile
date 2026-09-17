# Manor Ledger — developer shortcuts. PHP 8.3 runs in Docker because the host
# ships an older PHP; on the VPS the same commands run with the system php.
# Every Docker target bind-mounts the whole repository, so data/ and the 0600
# key files in it are visible inside the container. No target uses host
# networking any more; `refresh` reaches Roblox over the default bridge.
PHP_IMG ?= php:8.3-cli
DOCKER  = docker run --rm -u "$$(id -u):$$(id -g)" -v "$(CURDIR)":/app -w /app

.PHONY: help deps test lint check build refresh ads-import serve import-legacy deploy voices voices-venv publish-voices

help:            ## Show this help
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*##' '{printf "  %-14s %s\n", $$1, $$2}'

deps:            ## Install dev dependencies (PHPUnit) with Composer in Docker
	$(DOCKER) -e COMPOSER_HOME=/tmp/composer composer:2 install --no-interaction --quiet

test:            ## Run the unit tests
	$(DOCKER) $(PHP_IMG) vendor/bin/phpunit

lint:            ## Syntax-check every PHP file
	$(DOCKER) $(PHP_IMG) sh -c "find src bin public tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l | grep -v 'No syntax errors' || echo lint ok"

check: lint test ## Lint + tests

build:           ## Compute data/dashboard.json from data/history.json
	$(DOCKER) $(PHP_IMG) php bin/build

refresh:         ## Fetch from Roblox into the local data dir (needs data/api-key)
	$(DOCKER) $(PHP_IMG) php bin/refresh

# Ads Manager has no API: EXPORT is the zip downloaded by hand from the ads
# dashboard (or the directory it was extracted to).
ads-import:      ## Import campaign spend: make ads-import EXPORT=~/Downloads/RobloxAdsReport_*.zip
	$(DOCKER) $(PHP_IMG) php bin/ads-import "$(EXPORT)"

import-legacy:   ## One-off: merge the old cache directory into data/history.json
	$(DOCKER) $(PHP_IMG) php bin/import-legacy $(LEGACY)

serve:           ## Dev server on http://127.0.0.1:8099 (host PHP)
	bin/serve 8099

deploy:          ## Install/update on the VPS (see deploy/install.sh)
	deploy/install.sh

voices-venv:     ## Create data/venv with youtube-transcript-api (host Python, not Docker)
	python3 -m venv data/venv
	data/venv/bin/pip install --quiet --upgrade pip
	data/venv/bin/pip install --quiet -r tools/requirements.txt

# Host PHP, exactly like deploy/systemd/user/manor-voices.service: the job
# shells out to data/venv/bin/python for transcripts, which the container has
# not got.
voices:          ## Build data/voices.json from YouTube (runs here, never on the VPS)
	/opt/lampp/bin/php bin/voices $(ARGS)

publish-voices:  ## Copy voices.json and the thumbnails to the VPS
	bin/voices-publish $(ARGS)
