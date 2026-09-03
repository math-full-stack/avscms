<?php
defined('_VALID') or die('Restricted Access!');

class VPagination
{
    /**
     * Builds <li> pagination items compatible with the admin servers template.
     *
     * @param int    $total_items total number of records
     * @param int    $limit       items per page
     * @param int    $page        current page (fallback to $_GET['page'])
     * @param string $add_query   extra query string (e.g. '&m=all') merged into links
     * @return string  empty when there is a single page, otherwise <li>...</li> markup
     */
    function getPagination( $total_items = 0, $limit = 20, $page = 1, $add_query = NULL )
    {
        $total_items = max(1, intval($total_items));
        $per_page    = max(1, intval($limit));
        $page        = ( isset($_GET['page']) && is_numeric($_GET['page']) ) ? intval($_GET['page']) : max(1, intval($page));
        $total_pages = intval(ceil($total_items / $per_page));

        if ( $page > $total_pages ) {
            $page = $total_pages;
        }
        if ( $total_pages <= 1 ) {
            return '';
        }

        // Current query string (without 'page') merged with the extra query pairs
        $params = array();
        foreach ( $_GET as $k => $v ) {
            if ( $k == 'page' ) {
                continue;
            }
            $params[$k] = $v;
        }
        if ( $add_query ) {
            foreach ( explode('&', ltrim($add_query, '&')) as $pair ) {
                if ( $pair == '' ) {
                    continue;
                }
                list($k, $v) = array_pad(explode('=', $pair, 2), 2, '');
                $params[$k] = urldecode($v);
            }
        }
        unset($params['page']);

        $script = ( isset($_SERVER['SCRIPT_NAME']) ) ? $_SERVER['SCRIPT_NAME'] : 'servers.php';
        $build_url = function ( $p ) use ( $script, $params ) {
            $params['page'] = $p;
            return $script . '?' . http_build_query($params);
        };

        $output   = array();
        $index    = 3; // how many page links to show around the current page

        // Previous
        if ( $page > 1 ) {
            $output[] = '<li class="prev"><a href="' . $build_url($page - 1) . '"><i class="fa fa-chevron-left"></i></a></li>';
        } else {
            $output[] = '<li class="prev disabled"><a href="#"><i class="fa fa-chevron-left"></i></a></li>';
        }

        // First pages
        if ( $total_pages > (($index * 2) + 3) && $page >= ($index + 3) ) {
            $output[] = '<li><a href="' . $build_url(1) . '">1</a></li>';
            $output[] = '<li><a href="' . $build_url(2) . '">2</a></li>';
        }
        if ( $page > $index + 3 ) {
            $output[] = '<li class="disabled"><span>..</span></li>';
        }

        // Window around the current page
        for ( $i = 1; $i <= $total_pages; $i++ ) {
            if ( $i == $page ) {
                $output[] = '<li class="active"><a href="#">' . $page . '</a></li>';
            } elseif ( ($i >= ($page - $index) && $i < $page) || ($i <= ($page + $index) && $i > $page) ) {
                $output[] = '<li><a href="' . $build_url($i) . '">' . $i . '</a></li>';
            }
        }

        // Last pages
        if ( $page < ($total_pages - 6) ) {
            $output[] = '<li class="disabled"><span>..</span></li>';
        }
        if ( $total_pages > (($index * 2) + 3) && $page <= $total_pages - ($index + 3) ) {
            $output[] = '<li><a href="' . $build_url($total_pages - 2) . '">' . ($total_pages - 2) . '</a></li>';
            $output[] = '<li><a href="' . $build_url($total_pages - 1) . '">' . ($total_pages - 1) . '</a></li>';
        }

        // Next
        if ( $page < $total_pages ) {
            $output[] = '<li class="next"><a href="' . $build_url($page + 1) . '"><i class="fa fa-chevron-right"></i></a></li>';
        } else {
            $output[] = '<li class="next disabled"><a href="#"><i class="fa fa-chevron-right"></i></a></li>';
        }

        return implode('', $output);
    }
}
?>
