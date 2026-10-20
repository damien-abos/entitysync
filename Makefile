# Entity Sync plugin for GLPI — development tasks.
#
# Requirements: php (8.2+), gettext tools (msgfmt, msgmerge); node for the JS check (optional).

PLUGIN   := entitysync
VERSION  := $(shell sed -n "s/^define('PLUGIN_ENTITYSYNC_VERSION', '\(.*\)');/\1/p" setup.php)
LANGS    := $(basename $(notdir $(wildcard locales/*.po)))
PHP_SRC  := $(shell find setup.php hook.php front ajax src tests tools -name '*.php')

.DEFAULT_GOAL := help
.PHONY: help lint test e2e check locales locales-mo locales-check package glpi-install clean version

help: ## Show this help
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

version: ## Print the plugin version (from setup.php)
	@echo $(VERSION)

lint: ## Syntax check of PHP, JavaScript and shell files
	@for f in $(PHP_SRC); do php -l $$f > /dev/null || exit 1; done
	@if command -v node > /dev/null; then node --check public/entitysync.js; else echo "node not found, JS check skipped"; fi
	@if command -v shellcheck > /dev/null; then shellcheck tools/*.sh .devcontainer/*.sh; else echo "shellcheck not found, shell check skipped"; fi
	@echo "lint ok"

test: ## Run the engine unit tests
	php tests/run.php

e2e: ## Run the end-to-end scenario against a running GLPI (see tests/e2e/run.php)
	php tests/e2e/run.php

check: lint test locales-check ## Lint, unit tests and translation checks

locales: ## Extract strings, update the .po files and compile them
	php tools/extract-locales.php locales/$(PLUGIN).pot
	@for lang in $(LANGS); do msgmerge --quiet --update --backup=none --no-fuzzy-matching locales/$$lang.po locales/$(PLUGIN).pot; done
	@$(MAKE) --no-print-directory locales-mo

locales-mo: ## Compile the .po files into .mo files
	@for lang in $(LANGS); do msgfmt --check -o locales/$$lang.mo locales/$$lang.po || exit 1; done

locales-check: ## Fail if the template is outdated or the French catalog is incomplete
	@tmp=$$(mktemp); php tools/extract-locales.php $$tmp 2> /dev/null; \
		if ! diff -q $$tmp locales/$(PLUGIN).pot > /dev/null; then \
			echo "locales/$(PLUGIN).pot is outdated: run 'make locales'"; diff -u locales/$(PLUGIN).pot $$tmp | head -40; rm -f $$tmp; exit 1; \
		fi; rm -f $$tmp
	@for lang in $(LANGS); do msgfmt --check -o /dev/null locales/$$lang.po || exit 1; done
	@stats=$$(LC_ALL=C msgfmt --statistics -o /dev/null locales/fr_FR.po 2>&1); echo "fr_FR: $$stats"; \
		if echo "$$stats" | grep -qE 'untranslated|fuzzy'; then echo "fr_FR.po is incomplete"; exit 1; fi

package: ## Build the deployable archive in dist/
	tools/build-package.sh

glpi-install: ## Install GLPI and the plugin for development (see tools/install-glpi.sh)
	tools/install-glpi.sh

clean: ## Remove build outputs
	rm -rf dist locales/*.mo
