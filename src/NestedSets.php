<?php
//The MIT License (MIT)
//
//Copyright (c) 2016 dev-lazarev.com
//
//Permission is hereby granted, free of charge, to any person obtaining a copy
//of this software and associated documentation files (the "Software"), to deal
//in the Software without restriction, including without limitation the rights
//to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
//copies of the Software, and to permit persons to whom the Software is
//furnished to do so, subject to the following conditions:
//
//The above copyright notice and this permission notice shall be included in all
//copies or substantial portions of the Software.
//
//THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
//IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
//FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
//AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
//LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
//OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
//SOFTWARE.

class NestedSets
{
    protected $db;

    public function __construct(IDataBaseWrapper $db)
    {
        $this->db = $db;
    }

    public function get($id = null)
    {
        $query = [];
        if ($id) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
            if ($row) {
                $query['left']['$gte'] = $row['left'];
                $query['right']['$lte'] = $row['right'];
            } else {
                return null;
            }
        }
        return $this->db->find($query, ['sort' => ['left' => 1]]);
    }

    public function getChild($id = null)
    {
        $query = [];
        if ($id) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
            if ($row) {
                $query['left']['$gt'] = $row['left'];
                $query['right']['$lt'] = $row['right'];
                return $this->db->find($query, ['sort' => ['left' => 1]]);
            }
        }
        return false;
    }

    public function getParent($id = null)
    {
        $query = [];
        if ($id) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
            if ($row) {
                $query['left']['$lte'] = $row['left'];
                $query['right']['$gte'] = $row['right'];
                return $this->db->find($query, ['sort' => ['left' => 1]]);
            }
        }
        return false;
    }

    public function getBranch($id = null)
    {
        $query = [];
        if ($id) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
            if ($row) {
                $query['right']['$gt'] = $row['left'];
                $query['left']['$lt'] = $row['right'];
                return $this->db->find($query, ['sort' => ['left' => 1]]);
            }
        }
        return false;
    }

    public function add($parentId = 0)
    {
        $right = 1;
        $level = 0;
        if ($parentId) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$parentId]);
            if ($row) {
                $level = $row['level'];
                $right = $row['right'];
            } else {
                $ret = $this->db->find([], ['sort' => ['right' => 1], 'limit' => 1]);
                if (!empty($ret)) {
                    foreach ($ret as $row) {
                        $level = $row['level'];
                        $right = $row['right'];
                    }
                }
            }
        }
        // Update the keys of an existing tree nodes behind parent node
        $this->db->update(
            [
                'left' => [
                    '$gt' => $right
                ]
            ],
            ['$inc' => [
                'left' => 2,
                'right' => 2
            ]
            ]
        );
        // Update the parent branch
        $this->db->update(
            [
                'right' => [
                    '$gte' => $right
                ],
                'left' => [
                    '$lt' => $right
                ]
            ],
            ['$inc' => [
                'right' => 2
            ]
            ]
        );
        // update the node
        $this->db->insert([
            $this->db->primaryKey() => $id = time(),
            'left' => $right,
            'right' => $right + 1,
            'level' => $level + 1
        ]);
        return $id;
    }

    public function remove($id)
    {
        if (!empty($id)) {
            $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
            if ($row) {
                $left = $row['left'];
                $right = $row['right'];
                // Remove the node (branch)
                $this->db->remove(
                    [
                        'left' =>
                            [
                                '$gte' => $left
                            ]
                        ,
                        'right' =>
                            [
                                '$lte' => $right
                            ]
                    ]
                );
                $offset = $right - $left + 1;
                // Update the parent branch
                $this->db->update(
                    [
                        'left' =>
                            [
                                '$lt' => $left
                            ]
                        ,
                        'right' =>
                            [
                                '$gt' => $right
                            ]
                    ],
                    ['$inc' => [
                        'right' => -$offset
                    ]
                    ]
                );
                // update the following sites
                $this->db->update(
                    [
                        'left' =>
                            [
                                '$gt' => $right
                            ]
                    ],
                    ['$inc' => [
                        'left' => -$offset,
                        'right' => -$offset
                    ]
                    ]
                );
                return true;
            }
        }
        return false;
    }

    public function move($id, $parentId = 0, $nearId = 0)
    {
        // Keys and level moving structure
        $row = $this->db->findOne([$this->db->primaryKey() => (int)$id]);
        if ($row) {
            $left = $row['left'];
            $right = $row['right'];
            $level = $row['level'];
        } else {
            return false;
        }

        $parent = $this->db->findOne([$this->db->primaryKey() => (int)$parentId]);
        if ($parent) {
            $levelUp = $parent['level'];
        } else {
            $levelUp = 0;
        }

        // define right key node for which we insert a node (branch)
        // transfer to the root
        if ($parent == 0) {
            $ret = $this->db->find([], ['sort' => ['right' => -1], 'limit' => 1]);
            if (!empty($ret)) {
                foreach ($ret as $row) {
                    $nearRight = $row['right'];
                }
            }
        } else {
            // moved to another node
            $parent = $this->db->findOne([$this->db->primaryKey() => (int)$parent]);
            $nearRight = $parent['right'] - 1;

            if (!empty($nearId)) {
                $near = $this->db->findOne([$this->db->primaryKey() => (int)$nearId]);
                if ($near) {
                    $nearRight = $near['right'];
                } else {
                    $nearRight = $parent['right'];
                }
            }
        }

        // define the offset
        $skewLevel = $levelUp - $level + 1; // level offset variable node
        $skewTree = $right - $left + 1; // shift keys tree
        //We get the id nodes movable branch
        $ids = [];
        $rows = $this->db->find(
            [
                'left' =>
                    [
                        '$lte' => $left
                    ]
                ,
                'right' =>
                    [
                        '$gte' => $right
                    ]
            ]
        );
        foreach ($rows as $row) {
            $ids[] = $row[$this->db->primaryKey()];
        }
        // move to higher units
        if ($right < $nearRight) {
            $skewEdit = $nearRight - $left + 1;
            $this->db->update(
                [
                    'right' =>
                        [
                            '$lt' => $left,
                            '$gt' => $nearRight
                        ]
                ],
                ['$inc' => [
                    'right' => $skewTree
                ]
                ]
            );
            $this->db->update(
                [
                    'left' =>
                        [
                            '$lt' => $left,
                            '$gt' => $nearRight
                        ]
                ],
                ['$inc' => [
                    'left' => $skewTree
                ]
                ]
            );
            // move the entire branch
            $this->db->update(
                [
                    $this->db->primaryKey() =>
                        [
                            '$in' => $ids
                        ]
                ],
                ['$inc' => [
                    'left' => $skewEdit,
                    'right' => $skewEdit,
                    'level' => $skewLevel,
                ]
                ]
            );
        } // move to lower-level units
        else {
            $skewEdit = $nearRight - $left + 1 - $skewTree;
            $this->db->update(
                [
                    'right' =>
                        [
                            '$lte' => $nearRight,
                            '$gt' => $right
                        ]
                ],
                ['$inc' => [
                    'right' => -$skewTree
                ]
                ]
            );
            $this->db->update(
                [
                    'left' =>
                        [
                            '$lt' => $nearRight,
                            '$gt' => $left
                        ]
                ],
                ['$inc' => [
                    'left' => -$skewTree
                ]
                ]
            );
            // move the entire branch
            $this->db->update(
                [
                    $this->db->primaryKey() =>
                        [
                            '$in' => $ids
                        ]
                ],
                ['$inc' => [
                    'left' => $skewEdit,
                    'right' => $skewEdit,
                    'level' => $skewLevel,
                ]
                ]
            );
        }
        return true;
    }
}