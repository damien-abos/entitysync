/**
 * Entity Sync plugin for GLPI
 *
 * Editors for the import model (levels and filter tree) and selection logic of the
 * preview panel. Plain JavaScript, no build step.
 *
 * @license GPL-3.0-or-later
 */
(function () {
    'use strict';

    if (window.EntitySync) {
        return;
    }

    var VALUE_OPERATORS = ['eq', 'neq', 'in', 'not_in', 'contains', 'not_contains', 'starts_with',
        'ends_with', 'regex', 'gt', 'gte', 'lt', 'lte'];
    var ALL_OPERATORS = VALUE_OPERATORS.concat(['empty', 'not_empty']);

    // ---------------------------------------------------------------------------------
    // DOM helpers
    // ---------------------------------------------------------------------------------

    /**
     * Creates an element.
     * @param {string} tag
     * @param {Object} [attrs] attributes; `class`, `text`, `on<Event>` handled specially
     * @param {Array<Node|string>} [children]
     */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key === 'class') {
                node.className = value;
            } else if (key === 'text') {
                node.textContent = value;
            } else if (key.indexOf('on') === 0 && typeof value === 'function') {
                node.addEventListener(key.substring(2).toLowerCase(), value);
            } else if (key === 'value') {
                node.value = value;
            } else if (key === 'checked') {
                node.checked = !!value;
            } else {
                node.setAttribute(key, value === true ? '' : value);
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined) {
                return;
            }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function iconButton(icon, title, onClick, extraClass) {
        return el('button', {
            type: 'button',
            class: 'btn btn-sm btn-ghost-secondary ' + (extraClass || ''),
            title: title,
            'aria-label': title,
            onClick: function (event) {
                event.preventDefault();
                onClick();
            }
        }, [el('i', {class: icon})]);
    }

    function helpText(text) {
        return el('div', {class: 'form-text mt-0 mb-1', text: text});
    }

    function sprintf(template, value) {
        return template.replace('%s', value);
    }

    // ---------------------------------------------------------------------------------
    // Levels editor
    // ---------------------------------------------------------------------------------

    function LevelsEditor(container, input, columnsListId, labels, readOnly) {
        this.container = container;
        this.input = input;
        this.columnsListId = columnsListId;
        this.labels = labels;
        this.readOnly = readOnly;
        try {
            this.levels = JSON.parse(input.value || '[]');
        } catch (e) {
            this.levels = [];
        }
        if (!Array.isArray(this.levels)) {
            this.levels = [];
        }
        this.levels = this.levels.map(this.normalize);
        this.render();
    }

    LevelsEditor.prototype.normalize = function (level) {
        level = level || {};
        return {
            label: level.label || '',
            key_columns: Array.isArray(level.key_columns) && level.key_columns.length ? level.key_columns : [''],
            name_column: level.name_column || '',
            name_rules: Array.isArray(level.name_rules) ? level.name_rules : [],
            comment_columns: Array.isArray(level.comment_columns) ? level.comment_columns : [],
            comment_skip_empty: level.comment_skip_empty === undefined ? true : !!level.comment_skip_empty,
            empty_label: level.empty_label || ''
        };
    };

    LevelsEditor.prototype.serialize = function () {
        var data = this.levels.map(function (level) {
            return {
                label: level.label.trim(),
                key_columns: level.key_columns.map(function (c) { return c.trim(); }).filter(Boolean),
                name_column: level.name_column.trim(),
                name_rules: level.name_rules.filter(function (r) { return (r.pattern || '') !== ''; }),
                comment_columns: level.comment_columns.filter(function (c) { return (c.column || '').trim() !== ''; }),
                comment_skip_empty: level.comment_skip_empty,
                empty_label: level.empty_label.trim()
            };
        });
        this.input.value = JSON.stringify(data);
    };

    LevelsEditor.prototype.changed = function (rerender) {
        this.serialize();
        if (rerender) {
            this.render();
        }
    };

    LevelsEditor.prototype.textInput = function (value, onInput, attrs) {
        var self = this;
        var input = el('input', Object.assign({
            type: 'text',
            class: 'form-control form-control-sm',
            value: value,
            disabled: self.readOnly,
            onInput: function () { onInput(input.value); self.changed(false); }
        }, attrs || {}));
        return input;
    };

    LevelsEditor.prototype.columnInput = function (value, onInput, placeholder) {
        return this.textInput(value, onInput, {list: this.columnsListId, placeholder: placeholder || this.labels.column});
    };

    LevelsEditor.prototype.render = function () {
        var self = this;
        var L = this.labels;
        this.container.innerHTML = '';

        if (this.levels.length === 0) {
            this.container.appendChild(el('div', {class: 'alert alert-info', text: L.no_levels}));
        }

        this.levels.forEach(function (level, index) {
            self.container.appendChild(self.renderLevel(level, index));
        });

        if (!this.readOnly) {
            this.container.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-outline-primary',
                onClick: function (event) {
                    event.preventDefault();
                    self.levels.push(self.normalize({}));
                    self.changed(true);
                }
            }, [el('i', {class: 'ti ti-plus me-1'}), L.add_level]));
        }
        this.serialize();
    };

    LevelsEditor.prototype.renderLevel = function (level, index) {
        var self = this;
        var L = this.labels;
        var levels = this.levels;

        var header = el('div', {class: 'card-header py-2 d-flex align-items-center gap-2'}, [
            el('span', {class: 'badge bg-primary-lt', text: L.level + ' ' + (index + 1)}),
            el('div', {class: 'flex-grow-1', style: 'max-width: 22rem'}, [
                this.textInput(level.label, function (v) { level.label = v; }, {placeholder: L.level_label, 'aria-label': L.level_label})
            ]),
            el('div', {class: 'ms-auto'}, this.readOnly ? [] : [
                index > 0 ? iconButton('ti ti-arrow-up', L.move_up, function () {
                    levels.splice(index - 1, 0, levels.splice(index, 1)[0]);
                    self.changed(true);
                }) : null,
                index < levels.length - 1 ? iconButton('ti ti-arrow-down', L.move_down, function () {
                    levels.splice(index + 1, 0, levels.splice(index, 1)[0]);
                    self.changed(true);
                }) : null,
                iconButton('ti ti-trash', L.remove, function () {
                    levels.splice(index, 1);
                    self.changed(true);
                }, 'text-danger')
            ])
        ]);

        var body = el('div', {class: 'card-body py-2'}, [
            el('div', {class: 'row g-3'}, [
                el('div', {class: 'col-12 col-lg-6'}, [
                    el('label', {class: 'form-label mb-0', text: L.key_columns}),
                    helpText(L.key_columns_help),
                    this.renderStringList(level.key_columns, true)
                ]),
                el('div', {class: 'col-12 col-lg-6'}, [
                    el('label', {class: 'form-label mb-0', text: L.name_column}),
                    helpText(L.name_column_help),
                    this.columnInput(level.name_column, function (v) { level.name_column = v; }),
                    el('label', {class: 'form-label mb-0 mt-2', text: L.empty_label}),
                    helpText(L.empty_label_help),
                    this.textInput(level.empty_label, function (v) { level.empty_label = v; }, {
                        placeholder: sprintf(L.default_empty, (level.label || L.level).toLowerCase())
                    })
                ]),
                el('div', {class: 'col-12 col-lg-6'}, [
                    el('label', {class: 'form-label mb-0', text: L.name_rules}),
                    helpText(L.name_rules_help),
                    this.renderRules(level)
                ]),
                el('div', {class: 'col-12 col-lg-6'}, [
                    el('label', {class: 'form-label mb-0', text: L.comment_columns}),
                    helpText(L.comment_columns_help),
                    this.renderCommentColumns(level)
                ])
            ])
        ]);

        return el('div', {class: 'card mb-2 entitysync-level'}, [header, body]);
    };

    LevelsEditor.prototype.renderStringList = function (list, required) {
        var self = this;
        var L = this.labels;
        var wrapper = el('div', {class: 'd-flex flex-wrap gap-1 align-items-center'});

        list.forEach(function (value, i) {
            wrapper.appendChild(el('div', {class: 'input-group input-group-sm', style: 'width: 14rem'}, [
                self.columnInput(value, function (v) { list[i] = v; }),
                (self.readOnly || (required && list.length === 1)) ? null : el('button', {
                    type: 'button',
                    class: 'btn btn-outline-secondary',
                    title: L.remove,
                    'aria-label': L.remove,
                    onClick: function (event) {
                        event.preventDefault();
                        list.splice(i, 1);
                        self.changed(true);
                    }
                }, [el('i', {class: 'ti ti-x'})])
            ]));
        });

        if (!this.readOnly) {
            wrapper.appendChild(iconButton('ti ti-plus', L.add_column, function () {
                list.push('');
                self.changed(true);
            }));
        }
        return wrapper;
    };

    LevelsEditor.prototype.renderRules = function (level) {
        var self = this;
        var L = this.labels;
        var wrapper = el('div');

        level.name_rules.forEach(function (rule, i) {
            wrapper.appendChild(el('div', {class: 'd-flex gap-1 mb-1'}, [
                el('div', {style: 'flex: 3'}, [self.textInput(rule.pattern || '', function (v) { rule.pattern = v; test(); },
                    {placeholder: L.pattern, class: 'form-control form-control-sm font-monospace'})]),
                el('div', {style: 'flex: 2'}, [self.textInput(rule.replacement || '', function (v) { rule.replacement = v; test(); },
                    {placeholder: L.replacement, class: 'form-control form-control-sm font-monospace'})]),
                el('div', {style: 'width: 4rem'}, [self.textInput(rule.flags || '', function (v) { rule.flags = v; test(); },
                    {placeholder: L.flags, title: 'i, m, s, x, U', maxlength: 5, class: 'form-control form-control-sm font-monospace'})]),
                self.readOnly ? null : iconButton('ti ti-x', L.remove, function () {
                    level.name_rules.splice(i, 1);
                    self.changed(true);
                })
            ]));
        });

        if (!this.readOnly) {
            wrapper.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost-primary',
                onClick: function (event) {
                    event.preventDefault();
                    level.name_rules.push({pattern: '', replacement: '', flags: ''});
                    self.changed(true);
                }
            }, [el('i', {class: 'ti ti-plus me-1'}), L.add_rule]));
        }

        // Live test of the rules on a sample value.
        var sample = el('input', {type: 'text', class: 'form-control form-control-sm', placeholder: L.test_value, onInput: function () { test(); }});
        var output = el('code', {class: 'ms-2'});
        wrapper.appendChild(el('div', {class: 'd-flex align-items-center mt-1', title: L.regex_test_note}, [
            el('i', {class: 'ti ti-flask me-1 text-muted'}),
            el('div', {style: 'max-width: 14rem'}, [sample]),
            output
        ]));

        function test() {
            var value = sample.value;
            if (value === '') {
                output.textContent = '';
                return;
            }
            try {
                level.name_rules.forEach(function (rule) {
                    if (!rule.pattern) {
                        return;
                    }
                    var flags = 'gu' + (rule.flags || '').replace(/[^ims]/g, '');
                    value = value.replace(new RegExp(rule.pattern, flags), (rule.replacement || '').replace(/\\(\d)/g, '$$$1'));
                });
                value = value.replace(/\s+/g, ' ').trim();
                output.className = 'ms-2';
                output.textContent = value === sample.value ? L.no_match : '→ ' + value;
            } catch (e) {
                output.className = 'ms-2 text-danger';
                output.textContent = L.invalid_regex;
            }
        }

        return wrapper;
    };

    LevelsEditor.prototype.renderCommentColumns = function (level) {
        var self = this;
        var L = this.labels;
        var wrapper = el('div');

        level.comment_columns.forEach(function (item, i) {
            wrapper.appendChild(el('div', {class: 'd-flex gap-1 mb-1'}, [
                el('div', {style: 'flex: 1'}, [self.columnInput(item.column || '', function (v) { item.column = v; })]),
                el('div', {style: 'flex: 1'}, [self.textInput(item.label || '', function (v) { item.label = v; }, {placeholder: L.label})]),
                self.readOnly ? null : iconButton('ti ti-x', L.remove, function () {
                    level.comment_columns.splice(i, 1);
                    self.changed(true);
                })
            ]));
        });

        var skip = el('input', {
            type: 'checkbox',
            class: 'form-check-input',
            checked: level.comment_skip_empty,
            disabled: self.readOnly,
            onChange: function () { level.comment_skip_empty = skip.checked; self.changed(false); }
        });

        wrapper.appendChild(el('div', {class: 'd-flex align-items-center gap-3'}, [
            self.readOnly ? null : el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost-primary',
                onClick: function (event) {
                    event.preventDefault();
                    level.comment_columns.push({column: '', label: ''});
                    self.changed(true);
                }
            }, [el('i', {class: 'ti ti-plus me-1'}), L.add_comment_column]),
            level.comment_columns.length ? el('label', {class: 'form-check mb-0'}, [skip, el('span', {class: 'form-check-label', text: L.skip_empty})]) : null
        ]));

        return wrapper;
    };

    // ---------------------------------------------------------------------------------
    // Filter editor
    // ---------------------------------------------------------------------------------

    function FilterEditor(container, input, columnsListId, labels, readOnly) {
        this.container = container;
        this.input = input;
        this.columnsListId = columnsListId;
        this.labels = labels;
        this.readOnly = readOnly;
        var tree;
        try {
            tree = JSON.parse(input.value || '{}');
        } catch (e) {
            tree = {};
        }
        this.tree = this.normalizeGroup(tree);
        this.render();
    }

    FilterEditor.prototype.normalizeGroup = function (node) {
        var self = this;
        node = node && node.type === 'group' ? node : {type: 'group', logic: 'AND', negate: false, children: []};
        return {
            type: 'group',
            logic: (node.logic || 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND',
            negate: !!node.negate,
            children: (node.children || []).map(function (child) {
                return child && child.type === 'condition' ? self.normalizeCondition(child) : self.normalizeGroup(child);
            })
        };
    };

    FilterEditor.prototype.normalizeCondition = function (node) {
        var values = node.values !== undefined ? node.values : (node.value !== undefined ? [node.value] : []);
        return {
            type: 'condition',
            column: node.column || '',
            operator: ALL_OPERATORS.indexOf(node.operator) >= 0 ? node.operator : 'eq',
            values: Array.isArray(values) ? values.map(String) : [String(values)],
            negate: !!node.negate,
            case_sensitive: !!node.case_sensitive
        };
    };

    FilterEditor.prototype.serialize = function () {
        this.input.value = JSON.stringify(this.tree);
    };

    FilterEditor.prototype.changed = function (rerender) {
        this.serialize();
        if (rerender) {
            this.render();
        }
    };

    FilterEditor.prototype.render = function () {
        this.container.innerHTML = '';
        this.container.appendChild(this.renderGroup(this.tree, null, 0));
        this.serialize();
    };

    FilterEditor.prototype.notToggle = function (node) {
        var self = this;
        var box = el('input', {
            type: 'checkbox',
            class: 'form-check-input',
            checked: node.negate,
            disabled: this.readOnly,
            onChange: function () {
                node.negate = box.checked;
                self.changed(true);
            }
        });
        return el('label', {class: 'form-check form-check-inline mb-0 me-1', title: this.labels.not}, [
            box,
            el('span', {class: 'form-check-label fw-bold' + (node.negate ? ' text-danger' : ''), text: this.labels.not})
        ]);
    };

    FilterEditor.prototype.renderGroup = function (group, parent, depth) {
        var self = this;
        var L = this.labels;

        var logic = el('select', {
            class: 'form-select form-select-sm w-auto',
            disabled: this.readOnly,
            onChange: function () {
                group.logic = logic.value;
                self.changed(true);
            }
        }, [
            el('option', {value: 'AND', text: L.and}),
            el('option', {value: 'OR', text: L.or})
        ]);
        logic.value = group.logic;

        var header = el('div', {class: 'd-flex align-items-center gap-2 mb-2 flex-wrap'}, [
            this.notToggle(group),
            el('span', {class: 'text-muted small', text: L.group}),
            logic,
            this.readOnly ? null : el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost-primary',
                onClick: function (event) {
                    event.preventDefault();
                    group.children.push(self.normalizeCondition({}));
                    self.changed(true);
                }
            }, [el('i', {class: 'ti ti-plus me-1'}), L.add_condition]),
            this.readOnly ? null : el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost-primary',
                onClick: function (event) {
                    event.preventDefault();
                    group.children.push(self.normalizeGroup({logic: group.logic === 'AND' ? 'OR' : 'AND'}));
                    self.changed(true);
                }
            }, [el('i', {class: 'ti ti-brackets me-1'}), L.add_group]),
            parent && !this.readOnly ? el('div', {class: 'ms-auto'}, [iconButton('ti ti-trash', L.remove, function () {
                parent.children.splice(parent.children.indexOf(group), 1);
                self.changed(true);
            }, 'text-danger')]) : null
        ]);

        var children = el('div', {class: 'entitysync-group-children'});
        if (group.children.length === 0 && !parent) {
            children.appendChild(el('div', {class: 'text-muted small', text: L.empty_filter}));
        }
        group.children.forEach(function (child, index) {
            if (index > 0) {
                children.appendChild(el('div', {class: 'entitysync-logic-separator', text: group.logic === 'OR' ? L.or : L.and}));
            }
            children.appendChild(child.type === 'condition'
                ? self.renderCondition(child, group)
                : self.renderGroup(child, group, depth + 1));
        });

        return el('div', {class: 'entitysync-group entitysync-group-' + (group.logic === 'OR' ? 'or' : 'and') + (group.negate ? ' entitysync-group-not' : '')}, [header, children]);
    };

    FilterEditor.prototype.renderCondition = function (cond, parent) {
        var self = this;
        var L = this.labels;

        var column = el('input', {
            type: 'text',
            class: 'form-control form-control-sm',
            list: this.columnsListId,
            placeholder: L.column,
            value: cond.column,
            disabled: this.readOnly,
            onInput: function () { cond.column = column.value; self.changed(false); }
        });

        var operator = el('select', {
            class: 'form-select form-select-sm',
            disabled: this.readOnly,
            onChange: function () {
                cond.operator = operator.value;
                self.changed(true);
            }
        }, ALL_OPERATORS.map(function (op) {
            return el('option', {value: op, text: L['op_' + op] || op});
        }));
        operator.value = cond.operator;

        var needsValue = VALUE_OPERATORS.indexOf(cond.operator) >= 0;
        var values = el('textarea', {
            class: 'form-control form-control-sm font-monospace',
            rows: Math.min(Math.max(cond.values.length, 1), 6),
            placeholder: L.values,
            title: L.values_help,
            disabled: this.readOnly,
            onInput: function () {
                cond.values = values.value.split(/\r?\n/);
                self.changed(false);
            }
        });
        values.value = cond.values.join('\n');

        var caseBox = el('input', {
            type: 'checkbox',
            class: 'form-check-input',
            checked: cond.case_sensitive,
            disabled: this.readOnly,
            onChange: function () { cond.case_sensitive = caseBox.checked; self.changed(false); }
        });

        return el('div', {class: 'entitysync-condition d-flex gap-2 align-items-start flex-wrap'}, [
            this.notToggle(cond),
            el('div', {style: 'width: 11rem'}, [column]),
            el('div', {style: 'width: 14rem'}, [operator]),
            // Values, case flag and remove button wrap together on narrow screens.
            el('div', {class: 'd-flex gap-2 align-items-start', style: 'flex: 1; min-width: 14rem'}, [
                needsValue ? el('div', {style: 'flex: 1'}, [values]) : el('div', {style: 'flex: 1'}),
                needsValue ? el('label', {class: 'form-check mb-0 mt-1 text-nowrap', title: L.case_sensitive}, [
                    caseBox, el('span', {class: 'form-check-label small', text: 'Aa'})
                ]) : null,
                this.readOnly ? null : iconButton('ti ti-x', L.remove, function () {
                    parent.children.splice(parent.children.indexOf(cond), 1);
                    self.changed(true);
                })
            ])
        ]);
    };

    // ---------------------------------------------------------------------------------
    // Preview panel
    // ---------------------------------------------------------------------------------

    function initPreview(formId) {
        var form = document.getElementById(formId);
        if (!form || form.dataset.esReady) {
            return;
        }
        form.dataset.esReady = '1';

        var boxes = {};
        var dependents = {};
        Array.prototype.forEach.call(form.querySelectorAll('[data-es-op]'), function (box) {
            boxes[box.dataset.esOp] = box;
        });
        Object.keys(boxes).forEach(function (id) {
            var requires = JSON.parse(boxes[id].dataset.esRequires || '[]');
            boxes[id].esRequires = requires;
            requires.forEach(function (required) {
                (dependents[required] = dependents[required] || []).push(id);
            });
        });

        function setChecked(id, checked, visited) {
            var box = boxes[id];
            if (!box || box.disabled || visited[id]) {
                return;
            }
            visited[id] = true;
            box.checked = checked;
            if (checked) {
                // A selected operation needs the operations it depends on.
                box.esRequires.forEach(function (required) { setChecked(required, true, visited); });
            } else {
                // An unselected operation cannot be the prerequisite of a selected one.
                (dependents[id] || []).forEach(function (dependent) { setChecked(dependent, false, visited); });
            }
        }

        function visibleBoxes(scope) {
            return Array.prototype.filter.call((scope || form).querySelectorAll('[data-es-op]'), function (box) {
                return !box.disabled && box.closest('[data-es-row]').style.display !== 'none';
            });
        }

        var counter = form.querySelector('[data-es-counter]');
        function refresh() {
            var selected = 0;
            var deletions = 0;
            Object.keys(boxes).forEach(function (id) {
                if (boxes[id].checked) {
                    selected++;
                    if (boxes[id].dataset.esType === 'delete') {
                        deletions++;
                    }
                }
            });
            if (counter) {
                counter.textContent = counter.dataset.template.replace('%1$d', selected).replace('%2$d', deletions);
            }
            Array.prototype.forEach.call(form.querySelectorAll('[data-es-section-toggle]'), function (toggle) {
                var section = form.querySelector('[data-es-section="' + toggle.dataset.esSectionToggle + '"]');
                var enabled = Array.prototype.filter.call(section.querySelectorAll('[data-es-op]'), function (b) { return !b.disabled; });
                var checked = enabled.filter(function (b) { return b.checked; });
                toggle.disabled = enabled.length === 0;
                toggle.checked = enabled.length > 0 && checked.length === enabled.length;
                toggle.indeterminate = checked.length > 0 && checked.length < enabled.length;
            });
            return {selected: selected, deletions: deletions};
        }

        form.addEventListener('change', function (event) {
            var target = event.target;
            if (target.dataset.esOp) {
                setChecked(target.dataset.esOp, target.checked, {});
            } else if (target.dataset.esSectionToggle) {
                var section = form.querySelector('[data-es-section="' + target.dataset.esSectionToggle + '"]');
                visibleBoxes(section).forEach(function (box) { setChecked(box.dataset.esOp, target.checked, {}); });
            }
            refresh();
        });

        Array.prototype.forEach.call(form.querySelectorAll('[data-es-select]'), function (button) {
            button.addEventListener('click', function () {
                var checked = button.dataset.esSelect === 'all';
                visibleBoxes().forEach(function (box) { setChecked(box.dataset.esOp, checked, {}); });
                refresh();
            });
        });

        var search = form.querySelector('[data-es-search]');
        if (search) {
            search.addEventListener('input', function () {
                var term = search.value.trim().toLowerCase();
                Array.prototype.forEach.call(form.querySelectorAll('[data-es-row]'), function (row) {
                    row.style.display = term === '' || row.dataset.path.indexOf(term) >= 0 ? '' : 'none';
                });
            });
            // Enter in the search field must not submit the form.
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        }

        var apply = form.querySelector('[data-es-apply]');
        if (apply) {
            apply.addEventListener('click', function (event) {
                var state = refresh();
                if (state.selected === 0) {
                    event.preventDefault();
                    return;
                }
                if (state.deletions > 0 && !window.confirm(apply.dataset.confirmDelete.replace('%d', state.deletions))) {
                    event.preventDefault();
                }
            });
        }

        refresh();
    }

    // ---------------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------------

    window.EntitySync = {
        initModelForm: function (options) {
            var levelsContainer = document.getElementById(options.levelsContainer);
            if (!levelsContainer || levelsContainer.dataset.esReady) {
                return;
            }
            levelsContainer.dataset.esReady = '1';
            new LevelsEditor(
                levelsContainer,
                document.getElementById(options.levelsInput),
                options.columnsList,
                options.labels,
                options.readOnly
            );
            new FilterEditor(
                document.getElementById(options.filterContainer),
                document.getElementById(options.filterInput),
                options.columnsList,
                options.labels,
                options.readOnly
            );
        },
        initPreview: initPreview
    };
})();
