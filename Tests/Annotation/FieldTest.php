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

namespace Rollerworks\RecordFilterBundle\TestAnnotation;

use Rollerworks\RecordFilterBundle\Annotation\Field as FilterField;

class FieldTest extends \PHPUnit_Framework_TestCase
{
    function testName()
    {
        $field = new FilterField(array('name' => 'User'));
        $this->assertEquals('User', $field->getName());

        $field = new FilterField(array('value' => 'User'));
        $this->assertEquals('User', $field->getName());
    }

    function testNameReq()
    {
        $this->setExpectedException('\UnexpectedValueException', "Property 'name' on annotation 'Rollerworks\\RecordFilterBundle\\Annotation\\Field' is required.");
        new FilterField(array('type' => 'User'));
    }

    function testRequired()
    {
        $field = new FilterField(array('name' => 'User', 'Required' => false));
        $this->assertFalse($field->isRequired());

        $field = new FilterField(array('name' => 'User', 'Required' => true));
        $this->assertTrue($field->isRequired());
    }

    function testType()
    {
        $field = new FilterField(array('name' => 'User', 'Type' => 'Number'));
        $this->assertEquals('Number', $field->getType());
    }

    function testAcceptRanges()
    {
        $field = new FilterField(array('name' => 'User', 'AcceptRanges' => true));
        $this->assertTrue($field->acceptsRanges());

        $field = new FilterField(array('name' => 'User', 'AcceptRanges' => false));
        $this->assertFalse($field->acceptsRanges());
    }

    function testAcceptCompares()
    {
        $field = new FilterField(array('name' => 'User', 'AcceptCompares' => false));
        $this->assertFalse($field->acceptsCompares());

        $field = new FilterField(array('name' => 'User', 'AcceptCompares' => true));
        $this->assertTrue($field->acceptsCompares());
    }

    function testConstructParams()
    {
        $field = new FilterField(array('name' => 'User', '_lang' => 'en' ));
        $this->assertEquals(array('lang' => 'en'), $field->getParams());
    }

    function testUnknownProp()
    {
        $this->setExpectedException('\BadMethodCallException', "Unknown property 'doctor' on annotation 'Rollerworks\\RecordFilterBundle\\Annotation\\Field'.");
        new FilterField(array('name' => 'User', 'doctor' => 'who' ));
    }

    function testWidgetEmpty()
    {
        $field = new FilterField(array('name' => 'User'));

        $widgetParams = $field->getWidget('js');
        $this->assertEquals(array(), $widgetParams);
    }

    function testWidget()
    {
        $field = new FilterField(array('name'                  => 'User',
                                        'widget_js_type'        => 'Number',
                                        'widget_js_template'    => 'divs.html.twig',

                                        'widget_flash_type'     => 'Float',
                                        'widget_flash_template' => 'tables.html.twig'));

        $widgetParams = $field->getWidget('js');
        $this->assertEquals(array('type' => 'Number', 'template' => 'divs.html.twig'), $widgetParams);

        $widgetParams = $field->getWidget('flash');
        $this->assertEquals(array('type' => 'Float', 'template' => 'tables.html.twig'), $widgetParams);
    }
}