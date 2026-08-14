<?php

/**
 * Stand-in for MC4WP_API_V3, recording the calls made by the connected site lookup.
 *
 * @ignore
 */
class MC4WP_Fake_Connected_Sites_API
{
    public $sites = [];
    public $sites_by_id = [];
    public $created = null;
    public $listed = 0;

    public function get_connected_site($site_id, array $args = [])
    {
        if (! isset($this->sites_by_id[ $site_id ])) {
            throw new MC4WP_API_Resource_Not_Found_Exception('Not Found', 404);
        }

        return $this->sites_by_id[ $site_id ];
    }

    public function get_connected_sites(array $args = [])
    {
        $this->listed++;
        return $this->sites;
    }

    public function add_connected_site(array $args)
    {
        $this->created = $args;
        return (object) [
            'foreign_id'  => $args['foreign_id'],
            'domain'      => $args['domain'],
            'site_script' => (object) [ 'url' => 'https://chimpstatic.com/mcjs-connected/js/users/created.js' ],
        ];
    }
}
