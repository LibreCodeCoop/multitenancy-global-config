<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Stands in for the module loader, in an app directory named after the old
 * repository name, so the lookup is exercised against a clone that is not
 * named exactly like the repository.
 */

$multitenancyRequiredLoader = __FILE__;
