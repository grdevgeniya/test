<?php

/**
 * This file is part of the RollerworksRecordFilterBundle.
 *
 * (c) Rollerscapes
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link    http://projects.rollerscapes.net/RollerFramework
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */

namespace Rollerworks\RecordFilterBundle\Dumper;

use Rollerworks\RecordFilterBundle\Formatter\FormatterInterface;
use Rollerworks\RecordFilterBundle\FilterStruct;

/**
 * Dump the filtering preferences as JSON (JavaScript Object Notation).
 *
 * @link http://www.json.org/
 *
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 */
class JSON extends AbstractDumper
{
    /**
     * Returns the filtering preference as JSON.
     *
     * The returned structure depends on $flattenValues.
     *
     * When the values are flattened they are in the following format.
     * Each entry value is a group with the fields and there values (as Array)
     *
     * Example:
     * <code>
     *   [ { "field1" : [ "value1", "value2" ] }, { "field1" : [ "value1", "value2" ] } ]
     * </code>
     *
     * In none-flattened format, the fields are returned as follow.
     * Each entry value is a group with the fields and there values per type, types maybe empty.
     *
     * <code>
     *   [ { "field1" : {
     *          "single-values": ["value", "value2"],
     *          "excluded-values": ["value", "value2"],
     *          "ranges": [ { "lower": "12", "higher": "20" } ],
     *          "excluded-ranges": [ { "lower": "12", "higher": "20" } ],
     *          "compares": [ {"opr": ">", "value": "value" } ]
     *        }
     *     },
     *     { "field1" : {
     *          "single-values": ["value", "value2"]
     *        }
     *     } ]
     * </code>
     *
     * @param \Rollerworks\RecordFilterBundle\Formatter\FormatterInterface $formatter
     * @param bool                                                                   $flattenValues
     * @return string JSON array
     */
    public function dumpFilters(FormatterInterface $formatter, $flattenValues = false)
    {
        $filters = array();

        if ($flattenValues) {
            foreach ($formatter->getFilters() as $groupIndex => $fields) {
                foreach ($fields as $field => $values) {
                    $filters[$groupIndex][$field] = self::filterStructToArray($values);
                }
            }
        }
        else {
            foreach ($formatter->getFilters() as $groupIndex => $fields) {
                foreach ($fields as $field => $values) {
                    $filters[$groupIndex][$field] = self::createField($values);
                }
            }
        }

        return json_encode($filters);
    }

    /**
     * Create the field {object}
     *
     * @param \Rollerworks\RecordFilterBundle\FilterStruct $filter
     * @return array
     */
    private static function createField(FilterStruct $filter)
    {
        $field = array();

        if ($filter->hasSingleValues()) {
            $field['single-values'] = array();

            foreach ($filter->getSingleValues() as $value) {
                $field['single-values'][] = (string) $value->getValue();
            }
        }

        if ($filter->hasExcludes()) {
            $field['excluded-values'] = array();

            foreach ($filter->getExcludes() as $value) {
                $field['excluded-values'][] = (string) $value->getValue();
            }
        }

        if ($filter->hasRanges()) {
            $field['ranges'] = array();

            foreach ($filter->getRanges() as $range) {
                $field['ranges'] = array('lower'  => (string) $range->getLower(),
                                         'higher' => (string) $range->getHigher());
            }
        }

        if ($filter->hasExcludedRanges()) {
            $field['excluded-ranges'] = array();

            foreach ($filter->getExcludedRanges() as $range) {
                $field['excluded-ranges'] = array('lower'  => (string) $range->getLower(),
                                                  'higher' => (string) $range->getHigher());
            }
        }

        if ($filter->hasCompares()) {
            $field['compares'] = array();

            foreach ($filter->getCompares() as $compare) {
                $field['compares'][] = array('opr'   => (string) $compare->getOperator(),
                                             'value' => (string) $compare->getValue());
            }
        }

        return $field;
    }
}
