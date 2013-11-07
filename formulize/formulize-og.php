<?php

/**
 * Project:     Formulize: data management rapid application development
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */


/**
* Responds when a OG membership is inserted.
*
* This hook is invoked after the OG membership is inserted into the database.
*
* @param OgMembership $og_membership
*   The OG membership that is being inserted.
*
* @see hook_entity_insert()
*/
function formulize_og_membership_insert(OgMembership $og_membership) {
    if (!_formulize_integration_init())
        return;

    // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
    Formulize::addUserToGroup($og_membership->etid, -1 * $og_membership->gid);
}


/**
* Responds to OG membership deletion.
*
* This hook is invoked after the OG membership has been removed from the database.
*
* @param OgMembership $og_membership
*   The OG membership that is being deleted.
*
* @see hook_entity_delete()
*/
function formulize_og_membership_delete(OgMembership $og_membership) {
    if (!_formulize_integration_init())
        return;

    // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
    Formulize::removeUserFromGroup($og_membership->etid, -1 * $og_membership->gid);
}


function _is_a_group_node($node) {
    // the og module probably has a function to do this, but if there is, it's well hidden
    return (is_a($node, "stdClass") and isset($node->type) and "group" == $node->type);
}


function formulize_node_insert($node) {
    if (_is_a_group_node($node)) {
        if (!_formulize_integration_init())
            return;

        // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
        Formulize::createGroup(-1 * $node->nid, $node->title, "", "Organic Group");
    }
}


function formulize_node_update($node) {
    if (_is_a_group_node($node)) {
        if (!_formulize_integration_init())
            return;

        // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
        Formulize::renameGroup(-1 * $node->nid, $node->title);
    }
}


function formulize_node_delete($node) {
    if (_is_a_group_node($node)) {
        if (!_formulize_integration_init())
            return;

        // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
        Formulize::deleteGroup(-1 * $node->nid);
    }
}


function _formulize_sync_organic_groups() {
    if (!_formulize_integration_init())
        return;

    // perform initial sync of organic groups that already exist
    $result = db_query("SELECT n.nid, n.title FROM {node} n WHERE n.type = 'group' ORDER BY n.title");
    foreach ($result as $group) {
        // since IDs for groups and organic groups collide, use negative values for og groups in the mapping table
        Formulize::createGroup(-1 * $group->nid, $group->title, "", "Organic Group");
    }
}
