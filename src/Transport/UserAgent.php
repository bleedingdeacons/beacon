<?php

declare(strict_types=1);

namespace Beacon\Transport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the descriptive `User-Agent` that outbound requests introduce
 * themselves with.
 *
 * Upstreams sitting behind bot protection challenge requests whose
 * user-agent tells them nothing — a bare product name, or the generic
 * `WordPress/6.x` default. The remedy asked for by the upstream is a
 * user-agent that names the application, its version, a human to
 * contact, and the deployment the traffic is coming from:
 *
 *     Beacon/1.2.3 (rest@aa-bristol.org; https://aa-bristol.org)
 *
 * The environment is this site's own home URL rather than a "test" /
 * "production" label, which makes the two deployments distinguishable
 * upstream without anyone having to maintain a label by hand.
 *
 * Beacon owns this builder rather than each driver reimplementing
 * it, so a driver plugin (Tamar and friends) identifies itself
 * with `UserAgent::forApp('Tamar', TAMAR_VERSION)` and gets the
 * same shape for free.
 */
final class UserAgent
{
    /**
     * Mailbox an upstream operator can reach a human on. Deliberately
     * a role address, not a personal one — it outlives whoever is
     * currently maintaining the suite.
     */
    public const CONTACT = 'rest@aa-bristol.org';

    /**
     * Beacon's own user-agent, for a transport no driver has named.
     */
    public static function library(): string
    {
        return self::forApp('Beacon', self::libraryVersion());
    }

    /**
     * Build the user-agent for one application.
     *
     * @param string $app     Product name, e.g. "Beacon".
     * @param string $version Product version; omitted from the string
     *                        when empty rather than emitting a bare
     *                        slash or a fake number.
     */
    public static function forApp(string $app, string $version = ''): string
    {
        $name = self::clean($app);
        if ($name === '') {
            $name = 'Beacon';
        }

        $version = self::clean($version);
        $product = $version === '' ? $name : $name . '/' . $version;

        return sprintf('%s (%s; %s)', $product, self::CONTACT, self::environment());
    }

    /**
     * The installed version of this package, as Composer recorded it.
     * There is no plugin header to read it from any more.
     */
    private static function libraryVersion(): string
    {
        $package = 'bleedingdeacons/beacon';
        if (
            !class_exists(\Composer\InstalledVersions::class)
            || !\Composer\InstalledVersions::isInstalled($package)
        ) {
            return '';
        }

        return (string) \Composer\InstalledVersions::getPrettyVersion($package);
    }

    /**
     * This deployment's home URL, without its trailing slash. Falls
     * back to "unknown" outside WordPress (CLI tooling, unit tests
     * that don't load the stub) so the string is still well-formed.
     */
    private static function environment(): string
    {
        if (!function_exists('home_url')) {
            return 'unknown';
        }

        $url = rtrim(self::clean((string) home_url('/')), '/');

        return $url === '' ? 'unknown' : $url;
    }

    /**
     * Strip what would break the header: newlines and tabs (header
     * injection), and the comment delimiters this format is built
     * from. A version string carrying a stray bracket must not be
     * able to close the comment early.
     */
    private static function clean(string $value): string
    {
        $collapsed = preg_replace('/[\r\n\t]+/', ' ', $value) ?? '';

        return trim(str_replace(['(', ')', ';'], '', $collapsed));
    }
}
