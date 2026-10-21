<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * An unrelated app that also happens to ship a src/loader.php. The lookup must
 * never pick it up.
 */

$multitenancyRequiredLoader = __FILE__;
