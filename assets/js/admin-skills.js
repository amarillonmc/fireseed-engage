// 种火集结号 - 管理后台技能机制组合器 / Fireseed Engage - Admin skill mechanism builder

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('skillCardModal');
        const form = document.getElementById('skillCardForm');
        if (!modal || !form) {
            return;
        }

        const createButton = document.getElementById('createCardButton');
        const closeButton = document.getElementById('closeModalButton');
        const cancelButton = document.getElementById('cancelModalButton');
        const builderModeButton = document.getElementById('builderModeButton');
        const legacyModeButton = document.getElementById('legacyModeButton');
        const builderPanel = document.getElementById('definitionBuilderPanel');
        const legacyPanel = document.getElementById('legacyJsonPanel');
        const builderLoading = document.getElementById('builderLoading');
        const definitionErrors = document.getElementById('definitionErrors');
        const definitionMode = document.getElementById('definitionMode');
        const effectJson = document.getElementById('effectJson');
        const activationType = document.getElementById('activationType');
        const applicationMode = document.getElementById('applicationMode');
        const includeCooldown = document.getElementById(
            'includeCooldownDefinition'
        );
        const cooldownToggleField = document.getElementById(
            'cooldownToggleField'
        );
        const cooldownEditorField = document.getElementById(
            'cooldownEditorField'
        );
        const durationEditorField = document.getElementById(
            'durationEditorField'
        );
        const cooldownValueEditor = document.getElementById(
            'cooldownValueEditor'
        );
        const durationValueEditor = document.getElementById(
            'durationValueEditor'
        );
        const effectList = document.getElementById('effectBuilderList');
        const addEffectButton = document.getElementById('addEffectButton');
        const saveSkillButton = document.getElementById('saveSkillButton');
        const placeholderList = document.getElementById(
            'placeholderMechanismList'
        );
        let catalogData = null;
        let catalogPromise = null;
        let controlSequence = 0;
        let modalGeneration = 0;
        let legacyAllowedForCurrentCard = false;

        /**
         * 为动态控件建立唯一且可访问的标签关系 / Gives a dynamic control a unique accessible label association
         *
         * @param {HTMLLabelElement} label 标签 / Label
         * @param {HTMLElement} control 表单控件 / Form control
         * @param {string} prefix ID前缀 / ID prefix
         * @returns {void}
         */
        function associateLabel(label, control, prefix) {
            controlSequence++;
            control.id = 'skill-builder-' + prefix + '-' + controlSequence;
            label.htmlFor = control.id;
        }

        /**
         * 读取JSON接口并保留HTTP错误语义 / Reads a JSON endpoint while preserving HTTP error semantics
         *
         * @param {string} url 接口地址 / Endpoint URL
         * @returns {Promise<object>} 响应对象 / Response object
         */
        function requestJson(url) {
            return fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function(response) {
                return response.json().then(function(data) {
                    if (!response.ok || !data.success) {
                        throw new Error(
                            data.message
                                || '请求失败 / Request failed'
                        );
                    }
                    return data;
                });
            });
        }

        /**
         * 仅加载一次受权限保护的机制目录 / Loads the permission-protected mechanism catalog once
         *
         * @returns {Promise<object>} 机制目录 / Mechanism catalog
         */
        function ensureCatalog() {
            if (catalogData) {
                return Promise.resolve(catalogData);
            }
            if (!catalogPromise) {
                catalogPromise = requestJson(
                    form.getAttribute('data-mechanism-api')
                ).then(function(data) {
                    if (!data.mechanisms
                        || !data.conditions
                        || !data.value_modes) {
                        throw new Error(
                            '机制目录响应不完整 / Mechanism catalog response is incomplete'
                        );
                    }
                    catalogData = data;
                    renderPlaceholderCatalog();
                    return data;
                }).catch(function(error) {
                    catalogPromise = null;
                    throw error;
                });
            }

            return catalogPromise;
        }

        /**
         * 显示可操作的双语错误 / Displays actionable bilingual errors
         *
         * @param {string[]} errors 错误列表 / Error list
         * @returns {void}
         */
        function showDefinitionErrors(errors) {
            definitionErrors.textContent = '';
            if (!errors || errors.length === 0) {
                definitionErrors.hidden = true;
                return;
            }

            const list = document.createElement('ul');
            errors.forEach(function(error) {
                const item = document.createElement('li');
                item.textContent = error;
                list.appendChild(item);
            });
            definitionErrors.appendChild(list);
            definitionErrors.hidden = false;
        }

        /**
         * 获取当前最高等级 / Gets the current maximum level
         *
         * @returns {number} 最高等级 / Maximum level
         */
        function getMaximumLevel() {
            const value = Number(document.getElementById('maxLevel').value);
            return Number.isInteger(value) && value >= 1 && value <= 100
                ? value
                : 1;
        }

        /**
         * 计算定义的嵌套深度与节点数量 / Measures definition nesting depth and node count
         *
         * @param {*} value 待测值 / Value to measure
         * @param {number} depth 当前深度 / Current depth
         * @returns {{depth: number, nodes: number}} 形状信息 / Shape information
         */
        function measureDefinitionShape(value, depth) {
            const currentDepth = typeof depth === 'number' ? depth : 1;
            if (value === null || typeof value !== 'object') {
                return { depth: currentDepth, nodes: 1 };
            }

            let maximumDepth = currentDepth;
            let nodes = 1;
            Object.keys(value).forEach(function(key) {
                const shape = measureDefinitionShape(
                    value[key],
                    currentDepth + 1
                );
                maximumDepth = Math.max(maximumDepth, shape.depth);
                nodes += shape.nodes;
            });
            return { depth: maximumDepth, nodes: nodes };
        }

        /**
         * 判断机制是否兼容当前发动与应用方式 / Checks whether a mechanism fits the current activation and application mode
         *
         * @param {object} definition 机制定义 / Mechanism definition
         * @returns {boolean} 是否兼容 / Whether compatible
         */
        function isMechanismCompatible(definition) {
            if (!definition || definition.status !== 'implemented') {
                return false;
            }
            if (definition.activation_types.indexOf(activationType.value) < 0) {
                return false;
            }

            if (activationType.value === 'passive') {
                return definition.kind === 'modifier';
            }
            if (applicationMode.value === 'instant') {
                return definition.kind === 'action';
            }
            if (applicationMode.value === 'timed') {
                return definition.kind === 'modifier'
                    || definition.kind === 'action';
            }

            return false;
        }

        /**
         * 读取机制参数当前值 / Reads the current value of a mechanism parameter
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @param {string} parameterName 参数名 / Parameter name
         * @returns {string|null} 参数值 / Parameter value
         */
        function getEffectParameterValue(card, parameterName) {
            const inputs = card.querySelectorAll('.parameter-input');
            for (let index = 0; index < inputs.length; index++) {
                if (inputs[index].getAttribute('data-parameter-name')
                    === parameterName) {
                    return inputs[index].value;
                }
            }
            return null;
        }

        /**
         * 按当前机制参数取得可执行条件作用域 / Gets the executable condition scope for current mechanism parameters
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @returns {{conditions: string[], phases: string[], values: object}} 条件作用域 / Condition scope
         */
        function getEffectConditionScope(card) {
            const mechanism = card.querySelector('.mechanism-select').value;
            const definition = catalogData.mechanisms[mechanism];
            if (!definition || definition.status !== 'implemented') {
                return { conditions: [], phases: [], values: {} };
            }

            let conditions = Array.isArray(definition.allowed_conditions)
                ? definition.allowed_conditions.slice()
                : [];
            let phases = Array.isArray(definition.allowed_phase_values)
                ? definition.allowed_phase_values.slice()
                : [];
            const conditionValues = {};
            Object.keys(
                definition.allowed_condition_values || {}
            ).forEach(function(conditionType) {
                const values =
                    definition.allowed_condition_values[conditionType];
                if (Array.isArray(values)) {
                    conditionValues[conditionType] = values.slice();
                }
            });
            const conditionOverrides =
                definition.allowed_conditions_by_parameter || {};
            Object.keys(conditionOverrides).forEach(function(parameterName) {
                const parameterValue = getEffectParameterValue(
                    card,
                    parameterName
                );
                const values = conditionOverrides[parameterName];
                if (parameterValue !== null
                    && values
                    && Array.isArray(values[parameterValue])) {
                    conditions = conditions.filter(function(conditionType) {
                        return values[parameterValue].indexOf(conditionType)
                            >= 0;
                    });
                }
            });
            const phaseOverrides =
                definition.allowed_phase_values_by_parameter || {};
            Object.keys(phaseOverrides).forEach(function(parameterName) {
                const parameterValue = getEffectParameterValue(
                    card,
                    parameterName
                );
                const values = phaseOverrides[parameterName];
                if (parameterValue !== null
                    && values
                    && Array.isArray(values[parameterValue])) {
                    phases = phases.filter(function(phase) {
                        return values[parameterValue].indexOf(phase) >= 0;
                    });
                }
            });
            const valueOverrides =
                definition.allowed_condition_values_by_parameter || {};
            Object.keys(valueOverrides).forEach(function(parameterName) {
                const parameterValue = getEffectParameterValue(
                    card,
                    parameterName
                );
                const values = valueOverrides[parameterName];
                if (parameterValue === null
                    || !values
                    || !values[parameterValue]) {
                    return;
                }
                Object.keys(values[parameterValue]).forEach(
                    function(conditionType) {
                        const allowed = values[parameterValue][conditionType];
                        if (!Array.isArray(allowed)) {
                            return;
                        }
                        if (!Array.isArray(
                            conditionValues[conditionType]
                        )) {
                            conditionValues[conditionType] = allowed.slice();
                            return;
                        }
                        conditionValues[conditionType] =
                            conditionValues[conditionType].filter(
                                function(value) {
                                    return allowed.indexOf(value) >= 0;
                                }
                            );
                    }
                );
            });

            return {
                conditions: conditions,
                phases: phases,
                values: conditionValues
            };
        }

        /**
         * 取得条件行当前草稿 / Captures the current condition-row draft
         *
         * @param {HTMLElement} row 条件行 / Condition row
         * @returns {object} 条件草稿 / Condition draft
         */
        function getConditionDraft(row) {
            const type = row.querySelector('.condition-type').value;
            const operator = row.querySelector('.condition-operator').value;
            const input = row.querySelector('.condition-value');
            let value = null;
            if (input && input.tagName === 'SELECT') {
                value = Array.prototype.filter.call(
                    input.options,
                    function(option) { return option.selected; }
                ).map(function(option) {
                    return option.value;
                });
                if (operator !== 'in' && operator !== 'not_in') {
                    value = value.length > 0 ? value[0] : null;
                }
            } else if (input) {
                value = parseFiniteNumber(input.value);
            }
            return { type: type, operator: operator, value: value };
        }

        /**
         * 参数变更后移除无运行钩子的条件并重建选项 / Removes hookless conditions and rebuilds options after parameter changes
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @returns {void}
         */
        function refreshEffectConditionScope(card) {
            const scope = getEffectConditionScope(card);
            const addButton = card.querySelector('.add-condition-button');
            addButton.disabled = scope.conditions.length === 0;
            addButton.title = scope.conditions.length === 0
                ? '该机制没有可用运行条件 / This mechanism exposes no runtime conditions'
                : '';

            Array.prototype.forEach.call(
                card.querySelectorAll('.condition-row'),
                function(row) {
                    const draft = getConditionDraft(row);
                    if (scope.conditions.indexOf(draft.type) < 0) {
                        row.remove();
                        return;
                    }

                    const typeSelect = row.querySelector('.condition-type');
                    typeSelect.textContent = '';
                    scope.conditions.forEach(function(type) {
                        const option = document.createElement('option');
                        option.value = type;
                        option.textContent = conditionTypeLabel(type);
                        typeSelect.appendChild(option);
                    });
                    typeSelect.value = draft.type;
                    renderConditionInputs(row, draft);
                }
            );
        }

        /**
         * 更新应用方式与元数据编辑器可见性 / Updates application-mode and metadata-editor visibility
         *
         * @returns {void}
         */
        function updateApplicationMode() {
            const passive = activationType.value === 'passive';
            if (passive) {
                applicationMode.value = 'continuous';
            } else if (applicationMode.value === 'continuous') {
                applicationMode.value = 'timed';
            }
            applicationMode.disabled = passive;
            cooldownToggleField.hidden = passive;
            cooldownEditorField.hidden = passive || !includeCooldown.checked;
            durationEditorField.hidden = passive
                || applicationMode.value !== 'timed';

            Array.prototype.forEach.call(
                effectList.querySelectorAll('.effect-card'),
                function(card) {
                    refreshMechanismSelect(card, true);
                    updateEffectAvailability(card);
                }
            );
        }

        /**
         * 建立数值模式选择器与输入区域 / Builds a value-mode selector and input region
         *
         * @param {HTMLElement} host 编辑器容器 / Editor container
         * @param {*} descriptor 既有描述符 / Existing descriptor
         * @returns {void}
         */
        function initializeValueEditor(host, descriptor) {
            host.textContent = '';
            const grid = document.createElement('div');
            grid.className = 'value-editor-grid';
            const modeField = document.createElement('div');
            modeField.className = 'builder-field';
            const modeLabel = document.createElement('label');
            modeLabel.textContent = '数值模式 / Value mode';
            const modeSelect = document.createElement('select');
            modeSelect.className = 'form-control value-mode';

            Object.keys(catalogData.value_modes).forEach(function(mode) {
                const option = document.createElement('option');
                option.value = mode;
                option.textContent = catalogData.value_modes[mode];
                modeSelect.appendChild(option);
            });
            associateLabel(modeLabel, modeSelect, 'value-mode');
            modeField.appendChild(modeLabel);
            modeField.appendChild(modeSelect);
            grid.appendChild(modeField);

            const range = document.createElement('div');
            range.className = 'value-range';
            range.textContent = '允许范围 / Allowed range: '
                + host.getAttribute('data-minimum')
                + ' – '
                + host.getAttribute('data-maximum');
            if (host.getAttribute('data-round-at-execution') === '1') {
                range.textContent += '；解析结果执行时取整'
                    + ' / resolved results are rounded at execution';
            }
            modeField.appendChild(range);

            const body = document.createElement('div');
            body.className = 'form-wide value-editor-body';
            grid.appendChild(body);
            host.appendChild(grid);

            let mode = 'fixed';
            if (descriptor && typeof descriptor === 'object'
                && !Array.isArray(descriptor)
                && typeof descriptor.mode === 'string'
                && catalogData.value_modes[descriptor.mode]) {
                mode = descriptor.mode;
            }
            modeSelect.value = mode;
            renderValueEditorBody(host, descriptor);
            modeSelect.addEventListener('change', function() {
                renderValueEditorBody(host, null);
            });
        }

        /**
         * 按数值模式渲染对应输入 / Renders inputs for the selected value mode
         *
         * @param {HTMLElement} host 编辑器容器 / Editor container
         * @param {*} descriptor 既有描述符 / Existing descriptor
         * @returns {void}
         */
        function renderValueEditorBody(host, descriptor) {
            const mode = host.querySelector('.value-mode').value;
            const body = host.querySelector('.value-editor-body');
            body.textContent = '';

            if (mode === 'fixed') {
                const label = document.createElement('label');
                label.textContent = '固定值 / Fixed value';
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'form-control fixed-value';
                input.min = host.getAttribute('data-minimum');
                input.max = host.getAttribute('data-maximum');
                input.step = 'any';
                if (typeof descriptor === 'number') {
                    input.value = String(descriptor);
                } else if (descriptor
                    && descriptor.mode === 'fixed'
                    && typeof descriptor.value === 'number') {
                    input.value = String(descriptor.value);
                } else {
                    input.value = host.getAttribute('data-minimum') || '0';
                }
                associateLabel(label, input, 'fixed-value');
                body.appendChild(label);
                body.appendChild(input);
                return;
            }

            if (mode === 'stat_level_values') {
                const statLabel = document.createElement('label');
                statLabel.textContent = '参照属性 / Referenced stat';
                const statSelect = document.createElement('select');
                statSelect.className = 'form-control stat-value';
                const stats = {
                    attack: '攻击 / Attack',
                    defense: '守备 / Defense',
                    speed: '速度 / Speed',
                    intelligence: '智力 / Intelligence'
                };
                Object.keys(stats).forEach(function(stat) {
                    const option = document.createElement('option');
                    option.value = stat;
                    option.textContent = stats[stat];
                    statSelect.appendChild(option);
                });
                if (descriptor && typeof descriptor.stat === 'string') {
                    statSelect.value = descriptor.stat;
                }
                associateLabel(statLabel, statSelect, 'stat-value');
                body.appendChild(statLabel);
                body.appendChild(statSelect);
            }

            const valuesLabel = document.createElement('label');
            const valuesInput = document.createElement('textarea');
            valuesInput.className = 'form-control curve-values';
            valuesInput.spellcheck = false;
            if (mode === 'cost_plus_intelligence_level_values') {
                valuesLabel.textContent = '每级：COST系数, 智力系数, 常数（可省略）'
                    + ' / Per level: cost coefficient, intelligence coefficient, optional constant';
                valuesInput.placeholder = '0.5, 0.01, 0\n0.6, 0.015, 0';
                if (descriptor && Array.isArray(descriptor.values)) {
                    valuesInput.value = descriptor.values.map(function(value) {
                        if (!value || typeof value !== 'object') {
                            return '';
                        }
                        return [
                            value.cost,
                            value.intelligence,
                            Object.prototype.hasOwnProperty.call(
                                value,
                                'constant'
                            ) ? value.constant : ''
                        ].join(', ');
                    }).join('\n');
                }
            } else {
                valuesLabel.textContent = 'Lv.1起的曲线值（逗号或空白分隔）'
                    + ' / Curve values from Lv.1 (comma or whitespace separated)';
                valuesInput.placeholder = '10, 12, 14, 16, 18';
                if (descriptor && Array.isArray(descriptor.values)) {
                    valuesInput.value = descriptor.values.join(', ');
                }
            }
            associateLabel(valuesLabel, valuesInput, 'curve-values');
            body.appendChild(valuesLabel);
            body.appendChild(valuesInput);

            const curveHelp = document.createElement('span');
            curveHelp.className = 'definition-help';
            curveHelp.textContent = '项数须正好等于当前最高等级 Lv.'
                + getMaximumLevel()
                + '。 / Must contain exactly max level Lv.'
                + getMaximumLevel() + ' entries.';
            body.appendChild(curveHelp);
        }

        /**
         * 将文本严格解析为有限数值 / Strictly parses text as a finite number
         *
         * @param {string} text 输入文本 / Input text
         * @returns {number|null} 数值或空 / Number or null
         */
        function parseFiniteNumber(text) {
            if (typeof text !== 'string' || text.trim() === '') {
                return null;
            }
            const value = Number(text);
            return Number.isFinite(value) ? value : null;
        }

        /**
         * 校验数值是否位于编辑器边界 / Checks whether a number is within editor bounds
         *
         * @param {HTMLElement} host 编辑器容器 / Editor container
         * @param {number} value 输入值 / Input value
         * @param {string} path 错误路径 / Error path
         * @param {string[]} errors 错误列表 / Error list
         * @returns {boolean} 是否有效 / Whether valid
         */
        function validateEditorNumber(host, value, path, errors) {
            const minimum = Number(host.getAttribute('data-minimum'));
            const maximum = Number(host.getAttribute('data-maximum'));
            if (!Number.isFinite(value)) {
                errors.push(path + ' 必须是有限数值 / must be a finite number');
                return false;
            }
            if (value < minimum || value > maximum) {
                errors.push(
                    path + ' 必须介于 ' + minimum + ' 与 ' + maximum
                    + ' / must be between ' + minimum + ' and ' + maximum
                );
                return false;
            }
            return true;
        }

        /**
         * 从编辑器读取安全数值描述符 / Reads a safe value descriptor from an editor
         *
         * @param {HTMLElement} host 编辑器容器 / Editor container
         * @param {string} path 错误路径 / Error path
         * @param {string[]} errors 错误列表 / Error list
         * @returns {*} 数值描述符 / Value descriptor
         */
        function readValueEditor(host, path, errors) {
            const mode = host.querySelector('.value-mode').value;
            if (!catalogData.value_modes[mode]) {
                errors.push(path + ' 使用未知模式 / uses an unknown mode');
                return null;
            }

            if (mode === 'fixed') {
                const value = parseFiniteNumber(
                    host.querySelector('.fixed-value').value
                );
                if (!validateEditorNumber(host, value, path, errors)) {
                    return null;
                }
                return { mode: 'fixed', value: value };
            }

            const values = [];
            if (mode === 'cost_plus_intelligence_level_values') {
                const lines = host.querySelector('.curve-values').value
                    .split(/\r?\n/)
                    .map(function(line) { return line.trim(); })
                    .filter(function(line) { return line !== ''; });
                lines.forEach(function(line, index) {
                    const terms = line.split(/[\s,]+/)
                        .filter(function(term) { return term !== ''; });
                    if (terms.length < 2 || terms.length > 3) {
                        errors.push(
                            path + '.values[' + index + '] 必须有2至3项'
                            + ' / must contain 2 or 3 terms'
                        );
                        return;
                    }
                    const cost = parseFiniteNumber(terms[0]);
                    const intelligence = parseFiniteNumber(terms[1]);
                    const constant = terms.length === 3
                        ? parseFiniteNumber(terms[2])
                        : null;
                    const validCost = validateEditorNumber(
                        host,
                        cost,
                        path + '.values[' + index + '].cost',
                        errors
                    );
                    const validIntelligence = validateEditorNumber(
                        host,
                        intelligence,
                        path + '.values[' + index + '].intelligence',
                        errors
                    );
                    const validConstant = constant === null
                        || validateEditorNumber(
                            host,
                            constant,
                            path + '.values[' + index + '].constant',
                            errors
                        );
                    if (validCost && validIntelligence && validConstant) {
                        const entry = {
                            cost: cost,
                            intelligence: intelligence
                        };
                        if (constant !== null) {
                            entry.constant = constant;
                        }
                        values.push(entry);
                    }
                });
            } else {
                const tokens = host.querySelector('.curve-values').value
                    .split(/[\s,]+/)
                    .filter(function(token) { return token !== ''; });
                tokens.forEach(function(token, index) {
                    const value = parseFiniteNumber(token);
                    if (validateEditorNumber(
                        host,
                        value,
                        path + '.values[' + index + ']',
                        errors
                    )) {
                        values.push(value);
                    }
                });
            }

            if (values.length !== getMaximumLevel()) {
                errors.push(
                    path + '.values 必须正好包含'
                    + getMaximumLevel() + '项'
                    + ' / must contain exactly ' + getMaximumLevel()
                    + ' entries'
                );
            }
            if (values.length > 100) {
                errors.push(path + '.values 最多100项 / may contain at most 100 entries');
            }

            const descriptor = { mode: mode, values: values };
            if (mode === 'stat_level_values') {
                descriptor.stat = host.querySelector('.stat-value').value;
            }
            return descriptor;
        }

        /**
         * 构建机制下拉选项并保留当前值 / Builds mechanism options while retaining the current value
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @param {boolean} preserveDetails 是否保留参数输入 / Whether to preserve detail inputs
         * @returns {void}
         */
        function refreshMechanismSelect(card, preserveDetails) {
            const select = card.querySelector('.mechanism-select');
            const previous = select.value;
            select.textContent = '';
            const available = document.createElement('optgroup');
            available.label = '可用于当前方式 / Available';
            const incompatible = document.createElement('optgroup');
            incompatible.label = '当前方式不兼容 / Incompatible';
            const placeholders = document.createElement('optgroup');
            placeholders.label = '尚未实现 / Placeholders';

            Object.keys(catalogData.mechanisms).forEach(function(code) {
                const definition = catalogData.mechanisms[code];
                const option = document.createElement('option');
                option.value = code;
                option.textContent = definition.label
                    + ' / ' + definition.label_en
                    + ' [' + code + ']';
                if (definition.status !== 'implemented') {
                    option.disabled = true;
                    placeholders.appendChild(option);
                } else if (!isMechanismCompatible(definition)) {
                    option.disabled = true;
                    incompatible.appendChild(option);
                } else {
                    available.appendChild(option);
                }
            });

            select.appendChild(available);
            select.appendChild(incompatible);
            select.appendChild(placeholders);
            if (previous && catalogData.mechanisms[previous]) {
                select.value = previous;
            }
            if (!select.value && available.children.length > 0) {
                select.value = available.children[0].value;
            }
            if (!preserveDetails) {
                renderEffectDetails(card, null);
            }
        }

        /**
         * 渲染机制参数、数值和说明 / Renders mechanism parameters, value, and description
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @param {object|null} effect 既有效果 / Existing effect
         * @returns {void}
         */
        function renderEffectDetails(card, effect) {
            const mechanism = card.querySelector('.mechanism-select').value;
            const definition = catalogData.mechanisms[mechanism];
            const description = card.querySelector('.mechanism-description');
            const parameterGrid = card.querySelector('.parameter-grid');
            const valueHost = card.querySelector('.effect-value-editor');
            parameterGrid.textContent = '';

            if (!definition) {
                description.textContent = '请选择已注册机制 / Select a registered mechanism';
                card.classList.add('invalid');
                return;
            }

            description.textContent = definition.description
                + ' / ' + definition.description_en;
            const code = document.createElement('span');
            code.className = 'mechanism-code';
            code.textContent = ' ' + mechanism;
            description.appendChild(code);

            Object.keys(definition.parameters || {}).forEach(function(name) {
                const parameter = definition.parameters[name];
                const field = document.createElement('div');
                field.className = 'parameter-field';
                const label = document.createElement('label');
                label.textContent = parameter.label;
                const select = document.createElement('select');
                select.className = 'form-control parameter-input';
                select.setAttribute('data-parameter-name', name);
                Object.keys(parameter.options || {}).forEach(function(value) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = parameter.options[value];
                    select.appendChild(option);
                });
                const existingValue = effect
                    && effect.parameters
                    && typeof effect.parameters[name] === 'string'
                    ? effect.parameters[name]
                    : parameter.default;
                if (existingValue) {
                    select.value = existingValue;
                }
                associateLabel(label, select, 'parameter-' + name);
                field.appendChild(label);
                field.appendChild(select);
                parameterGrid.appendChild(field);
                select.addEventListener('change', function() {
                    refreshEffectConditionScope(card);
                });
            });

            const valueDefinition = definition.value || {
                minimum: 0,
                maximum: 0,
                integer: false
            };
            valueHost.setAttribute(
                'data-minimum',
                String(valueDefinition.minimum)
            );
            valueHost.setAttribute(
                'data-maximum',
                String(valueDefinition.maximum)
            );
            valueHost.setAttribute(
                'data-round-at-execution',
                valueDefinition.integer ? '1' : '0'
            );
            initializeValueEditor(
                valueHost,
                effect ? effect.value : null
            );

            const conditionList = card.querySelector('.condition-list');
            conditionList.textContent = '';
            const conditions = effect && Array.isArray(effect.conditions)
                ? effect.conditions
                : [];
            conditions.forEach(function(condition) {
                addCondition(card, condition);
            });
            refreshEffectConditionScope(card);
            updateEffectAvailability(card);
        }

        /**
         * 标记当前机制是否可保存 / Marks whether the current mechanism can be saved
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @returns {void}
         */
        function updateEffectAvailability(card) {
            const mechanism = card.querySelector('.mechanism-select').value;
            const definition = catalogData.mechanisms[mechanism];
            card.classList.toggle(
                'invalid',
                !isMechanismCompatible(definition)
            );
        }

        /**
         * 添加一项可组合机制 / Adds one composable mechanism
         *
         * @param {object|null} effect 既有效果 / Existing effect
         * @returns {void}
         */
        function addEffect(effect) {
            const maximum = catalogData.limits
                ? Number(catalogData.limits.maximum_effects)
                : 32;
            if (effectList.children.length >= maximum) {
                showDefinitionErrors([
                    '单技能最多组合' + maximum + '个机制'
                    + ' / A skill may compose at most ' + maximum + ' mechanisms'
                ]);
                return;
            }

            const card = document.createElement('article');
            card.className = 'effect-card';
            const heading = document.createElement('div');
            heading.className = 'effect-card-heading';
            const number = document.createElement('span');
            number.className = 'effect-number';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'small-button danger remove-effect-button';
            remove.textContent = '移除 / Remove';
            heading.appendChild(number);
            heading.appendChild(remove);
            card.appendChild(heading);

            const grid = document.createElement('div');
            grid.className = 'effect-grid';
            const mechanismField = document.createElement('div');
            mechanismField.className = 'builder-field form-wide';
            const mechanismLabel = document.createElement('label');
            mechanismLabel.textContent = '机制 / Mechanism';
            const mechanismSelect = document.createElement('select');
            mechanismSelect.className = 'form-control mechanism-select';
            mechanismField.appendChild(mechanismLabel);
            associateLabel(
                mechanismLabel,
                mechanismSelect,
                'mechanism'
            );
            mechanismField.appendChild(mechanismSelect);
            grid.appendChild(mechanismField);

            const description = document.createElement('div');
            description.className = 'mechanism-description form-wide';
            grid.appendChild(description);
            const parameterGrid = document.createElement('div');
            parameterGrid.className = 'parameter-grid form-wide';
            grid.appendChild(parameterGrid);
            const valueHost = document.createElement('div');
            valueHost.className = 'value-editor effect-value-editor form-wide';
            grid.appendChild(valueHost);
            card.appendChild(grid);

            const conditions = document.createElement('div');
            conditions.className = 'conditions-block';
            const conditionHeading = document.createElement('div');
            conditionHeading.className = 'condition-heading';
            const conditionTitle = document.createElement('strong');
            conditionTitle.textContent = '生效条件 / Conditions';
            const addConditionButton = document.createElement('button');
            addConditionButton.type = 'button';
            addConditionButton.className = 'small-button add-condition-button';
            addConditionButton.textContent = '+ 添加条件 / Add condition';
            conditionHeading.appendChild(conditionTitle);
            conditionHeading.appendChild(addConditionButton);
            const conditionList = document.createElement('div');
            conditionList.className = 'condition-list';
            conditions.appendChild(conditionHeading);
            conditions.appendChild(conditionList);
            card.appendChild(conditions);
            effectList.appendChild(card);

            refreshMechanismSelect(card, true);
            if (effect && typeof effect.mechanism === 'string') {
                mechanismSelect.value = effect.mechanism;
            }
            renderEffectDetails(card, effect);
            renumberEffects();

            mechanismSelect.addEventListener('change', function() {
                renderEffectDetails(card, null);
            });
            remove.addEventListener('click', function() {
                card.remove();
                renumberEffects();
            });
            addConditionButton.addEventListener('click', function() {
                addCondition(card, null);
            });
        }

        /**
         * 重排机制序号 / Renumbers effect cards
         *
         * @returns {void}
         */
        function renumberEffects() {
            Array.prototype.forEach.call(
                effectList.querySelectorAll('.effect-number'),
                function(number, index) {
                    number.textContent = '机制 ' + (index + 1)
                        + ' / Effect ' + (index + 1);
                }
            );
        }

        /**
         * 添加一项结构化条件 / Adds one structured condition
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @param {object|null} condition 既有条件 / Existing condition
         * @returns {void}
         */
        function addCondition(card, condition) {
            const list = card.querySelector('.condition-list');
            const scope = getEffectConditionScope(card);
            if (scope.conditions.length === 0) {
                showDefinitionErrors([
                    '该机制没有可用运行条件'
                    + ' / This mechanism exposes no runtime conditions'
                ]);
                return;
            }
            const maximum = catalogData.limits
                ? Number(catalogData.limits.maximum_conditions)
                : 8;
            if (list.children.length >= maximum) {
                showDefinitionErrors([
                    '每项机制最多' + maximum + '个条件'
                    + ' / Each mechanism may have at most ' + maximum
                    + ' conditions'
                ]);
                return;
            }

            const row = document.createElement('div');
            row.className = 'condition-row';
            const grid = document.createElement('div');
            grid.className = 'condition-grid';
            const typeField = document.createElement('div');
            typeField.className = 'builder-field';
            const typeLabel = document.createElement('label');
            typeLabel.textContent = '条件 / Condition';
            const typeSelect = document.createElement('select');
            typeSelect.className = 'form-control condition-type';
            scope.conditions.forEach(function(type) {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = conditionTypeLabel(type);
                typeSelect.appendChild(option);
            });
            typeField.appendChild(typeLabel);
            associateLabel(typeLabel, typeSelect, 'condition-type');
            typeField.appendChild(typeSelect);

            const operatorField = document.createElement('div');
            operatorField.className = 'builder-field';
            const operatorLabel = document.createElement('label');
            operatorLabel.textContent = '运算 / Operator';
            const operatorSelect = document.createElement('select');
            operatorSelect.className = 'form-control condition-operator';
            operatorField.appendChild(operatorLabel);
            associateLabel(
                operatorLabel,
                operatorSelect,
                'condition-operator'
            );
            operatorField.appendChild(operatorSelect);

            const valueField = document.createElement('div');
            valueField.className = 'builder-field condition-value-field';
            const removeField = document.createElement('div');
            removeField.className = 'builder-field';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'small-button danger';
            remove.textContent = '移除条件 / Remove condition';
            removeField.appendChild(remove);

            grid.appendChild(typeField);
            grid.appendChild(operatorField);
            grid.appendChild(valueField);
            grid.appendChild(removeField);
            row.appendChild(grid);
            list.appendChild(row);

            if (condition
                && typeof condition.type === 'string'
                && scope.conditions.indexOf(condition.type) >= 0) {
                typeSelect.value = condition.type;
            }
            renderConditionInputs(row, condition);

            typeSelect.addEventListener('change', function() {
                renderConditionInputs(row, null);
            });
            operatorSelect.addEventListener('change', function() {
                renderConditionValue(row, null);
            });
            remove.addEventListener('click', function() {
                row.remove();
            });
        }

        /**
         * 获取条件的双语名称 / Gets a bilingual condition label
         *
         * @param {string} type 条件代码 / Condition code
         * @returns {string} 条件名称 / Condition label
         */
        function conditionTypeLabel(type) {
            const labels = {
                phase: '阶段 / Phase',
                side: '攻守方 / Side',
                target_tag: '目标标签 / Target tag',
                distance: '距离 / Distance'
            };
            return labels[type] || type;
        }

        /**
         * 渲染条件运算符和值输入 / Renders condition operator and value inputs
         *
         * @param {HTMLElement} row 条件行 / Condition row
         * @param {object|null} condition 既有条件 / Existing condition
         * @returns {void}
         */
        function renderConditionInputs(row, condition) {
            const type = row.querySelector('.condition-type').value;
            const definition = catalogData.conditions[type];
            const operator = row.querySelector('.condition-operator');
            operator.textContent = '';
            (definition.operators || []).forEach(function(code) {
                const option = document.createElement('option');
                option.value = code;
                option.textContent = operatorLabel(code);
                operator.appendChild(option);
            });
            if (condition
                && typeof condition.operator === 'string'
                && definition.operators.indexOf(condition.operator) >= 0) {
                operator.value = condition.operator;
            }
            renderConditionValue(row, condition);
        }

        /**
         * 获取运算符的双语名称 / Gets a bilingual operator label
         *
         * @param {string} operator 运算符 / Operator
         * @returns {string} 名称 / Label
         */
        function operatorLabel(operator) {
            const labels = {
                eq: '等于 / Equals',
                in: '属于 / In',
                not_in: '不属于 / Not in',
                lte: '小于等于 / Less than or equal',
                gte: '大于等于 / Greater than or equal',
                lt: '小于 / Less than',
                gt: '大于 / Greater than'
            };
            return labels[operator] || operator;
        }

        /**
         * 渲染条件值输入 / Renders the condition value input
         *
         * @param {HTMLElement} row 条件行 / Condition row
         * @param {object|null} condition 既有条件 / Existing condition
         * @returns {void}
         */
        function renderConditionValue(row, condition) {
            const type = row.querySelector('.condition-type').value;
            const operator = row.querySelector('.condition-operator').value;
            const definition = catalogData.conditions[type];
            const field = row.querySelector('.condition-value-field');
            field.textContent = '';
            const label = document.createElement('label');
            label.textContent = '值 / Value';
            field.appendChild(label);

            if (definition.type === 'number') {
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'form-control condition-value';
                input.min = String(definition.minimum);
                input.max = String(definition.maximum);
                input.step = 'any';
                input.value = condition
                    && typeof condition.value === 'number'
                    ? String(condition.value)
                    : String(definition.minimum);
                associateLabel(label, input, 'condition-value');
                field.appendChild(input);
                return;
            }

            const select = document.createElement('select');
            select.className = 'form-control condition-value';
            const multiple = operator === 'in' || operator === 'not_in';
            select.multiple = multiple;
            let optionValues = Object.keys(definition.options || {});
            if (type === 'phase') {
                const scope = getEffectConditionScope(
                    row.closest('.effect-card')
                );
                optionValues = optionValues.filter(function(value) {
                    return scope.phases.indexOf(value) >= 0;
                });
            } else {
                const scope = getEffectConditionScope(
                    row.closest('.effect-card')
                );
                if (Array.isArray(scope.values[type])) {
                    optionValues = optionValues.filter(function(value) {
                        return scope.values[type].indexOf(value) >= 0;
                    });
                }
            }
            optionValues.forEach(function(value) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = definition.options[value];
                select.appendChild(option);
            });
            const selected = condition
                ? (Array.isArray(condition.value)
                    ? condition.value
                    : [condition.value])
                : [];
            Array.prototype.forEach.call(select.options, function(option) {
                option.selected = selected.indexOf(option.value) >= 0;
            });
            const hasSelectedOption = Array.prototype.some.call(
                select.options,
                function(option) { return option.selected; }
            );
            if (!hasSelectedOption && select.options.length > 0) {
                select.options[0].selected = true;
            }
            associateLabel(label, select, 'condition-value');
            field.appendChild(select);
            if (multiple) {
                const help = document.createElement('span');
                help.className = 'definition-help';
                help.textContent = '可多选 / Multiple selections allowed';
                field.appendChild(help);
            }
        }

        /**
         * 从条件行读取标准条件 / Reads a normalized condition from a row
         *
         * @param {HTMLElement} row 条件行 / Condition row
         * @param {string} path 错误路径 / Error path
         * @param {string[]} errors 错误列表 / Error list
         * @returns {object|null} 条件 / Condition
         */
        function readCondition(row, path, errors) {
            const type = row.querySelector('.condition-type').value;
            const operator = row.querySelector('.condition-operator').value;
            const definition = catalogData.conditions[type];
            if (!definition) {
                errors.push(path + '.type 未注册 / is not registered');
                return null;
            }
            const scope = getEffectConditionScope(
                row.closest('.effect-card')
            );
            if (scope.conditions.indexOf(type) < 0) {
                errors.push(path + '.type 在该机制运行上下文中不可用'
                    + ' / is unavailable in this mechanism runtime context');
                return null;
            }
            if (definition.operators.indexOf(operator) < 0) {
                errors.push(path + '.operator 不受支持 / is not supported');
                return null;
            }

            const input = row.querySelector('.condition-value');
            let value;
            if (definition.type === 'number') {
                value = parseFiniteNumber(input.value);
                if (value === null
                    || value < Number(definition.minimum)
                    || value > Number(definition.maximum)) {
                    errors.push(path + '.value 超出允许范围 / is outside the allowed range');
                    return null;
                }
            } else {
                const selected = Array.prototype.filter.call(
                    input.options,
                    function(option) { return option.selected; }
                ).map(function(option) { return option.value; });
                if (selected.length === 0) {
                    errors.push(path + '.value 至少选择一项 / requires at least one selection');
                    return null;
                }
                value = operator === 'in' || operator === 'not_in'
                    ? selected
                    : selected[0];
            }
            if (type === 'phase') {
                const selectedPhases = Array.isArray(value)
                    ? value
                    : [value];
                const hasUnsupportedPhase = selectedPhases.some(
                    function(phase) {
                        return scope.phases.indexOf(phase) < 0;
                    }
                );
                if (hasUnsupportedPhase) {
                    errors.push(path
                        + '.value 在该机制中没有运行时消费者'
                        + ' / has no runtime consumer for this mechanism');
                    return null;
                }
            }
            const selectedValues = Array.isArray(value) ? value : [value];
            if (type !== 'phase'
                && Array.isArray(scope.values[type])
                && selectedValues.some(function(selectedValue) {
                    return scope.values[type].indexOf(selectedValue) < 0;
                })) {
                errors.push(path
                    + '.value 在该机制中没有运行时消费者'
                    + ' / has no runtime consumer for this mechanism');
                return null;
            }

            return { type: type, operator: operator, value: value };
        }

        /**
         * 从机制卡片读取单项效果 / Reads one effect from an effect card
         *
         * @param {HTMLElement} card 机制卡片 / Effect card
         * @param {number} index 项目索引 / Effect index
         * @param {string[]} errors 错误列表 / Error list
         * @returns {object|null} 效果 / Effect
         */
        function readEffect(card, index, errors) {
            const path = 'effects[' + index + ']';
            const mechanism = card.querySelector('.mechanism-select').value;
            const definition = catalogData.mechanisms[mechanism];
            if (!definition) {
                errors.push(path + '.mechanism 未注册 / is not registered');
                return null;
            }
            if (definition.status !== 'implemented') {
                errors.push(path + '.mechanism 目前仅为占位 / is currently a placeholder');
                return null;
            }
            if (!isMechanismCompatible(definition)) {
                errors.push(path + '.mechanism 与发动或应用方式不兼容'
                    + ' / is incompatible with activation or application mode');
            }

            const parameters = {};
            Array.prototype.forEach.call(
                card.querySelectorAll('.parameter-input'),
                function(input) {
                    parameters[input.getAttribute('data-parameter-name')]
                        = input.value;
                }
            );
            const value = readValueEditor(
                card.querySelector('.effect-value-editor'),
                path + '.value',
                errors
            );
            const conditions = [];
            Array.prototype.forEach.call(
                card.querySelectorAll('.condition-row'),
                function(row, conditionIndex) {
                    const condition = readCondition(
                        row,
                        path + '.conditions[' + conditionIndex + ']',
                        errors
                    );
                    if (condition) {
                        conditions.push(condition);
                    }
                }
            );

            return {
                mechanism: mechanism,
                parameters: parameters,
                value: value,
                conditions: conditions
            };
        }

        /**
         * 序列化第二版技能定义 / Serializes a version-two skill definition
         *
         * @returns {{definition: object, errors: string[]}} 序列化结果 / Serialization result
         */
        function serializeBuilder() {
            const errors = [];
            const definition = {
                schema_version: Number(catalogData.schema_version) || 2,
                application_mode: activationType.value === 'passive'
                    ? 'continuous'
                    : applicationMode.value,
                effects: []
            };
            const cards = effectList.querySelectorAll('.effect-card');
            if (cards.length === 0) {
                errors.push('至少添加一个机制 / Add at least one mechanism');
            }
            Array.prototype.forEach.call(cards, function(card, index) {
                const effect = readEffect(card, index, errors);
                if (effect) {
                    definition.effects.push(effect);
                }
            });
            if (activationType.value === 'active'
                && applicationMode.value === 'timed') {
                const hasModifier = definition.effects.some(function(effect) {
                    const mechanism = catalogData.mechanisms[effect.mechanism];
                    return mechanism && mechanism.kind === 'modifier';
                });
                if (!hasModifier) {
                    errors.push(
                        'timed主动技能至少需要一个修正'
                        + ' / Timed active skills need at least one modifier'
                    );
                }
            }
            if (activationType.value === 'active' && includeCooldown.checked) {
                definition.cooldown = readValueEditor(
                    cooldownValueEditor,
                    'cooldown',
                    errors
                );
            }
            if (activationType.value === 'active'
                && applicationMode.value === 'timed') {
                definition.duration = readValueEditor(
                    durationValueEditor,
                    'duration',
                    errors
                );
            }
            const shape = measureDefinitionShape(definition, 1);
            const maximumDepth = Number(catalogData.limits.maximum_depth);
            const maximumNodes = Number(catalogData.limits.maximum_nodes);
            if (Number.isFinite(maximumDepth)
                && shape.depth > maximumDepth) {
                errors.push(
                    '技能定义嵌套过深 / Skill definition is nested too deeply'
                );
            }
            if (Number.isFinite(maximumNodes)
                && shape.nodes > maximumNodes) {
                errors.push(
                    '技能定义项目过多（' + shape.nodes + '/' + maximumNodes + '）'
                    + ' / Skill definition contains too many nodes ('
                    + shape.nodes + '/' + maximumNodes + ')'
                );
            }

            return { definition: definition, errors: errors };
        }

        /**
         * 载入已有第二版定义 / Loads an existing version-two definition
         *
         * @param {object} definition 技能定义 / Skill definition
         * @returns {void}
         */
        function loadStructuredDefinition(definition) {
            applicationMode.value = typeof definition.application_mode === 'string'
                ? definition.application_mode
                : (activationType.value === 'passive' ? 'continuous' : 'timed');
            includeCooldown.checked = Object.prototype.hasOwnProperty.call(
                definition,
                'cooldown'
            );
            initializeValueEditor(
                cooldownValueEditor,
                includeCooldown.checked ? definition.cooldown : 0
            );
            initializeValueEditor(
                durationValueEditor,
                Object.prototype.hasOwnProperty.call(definition, 'duration')
                    ? definition.duration
                    : 60
            );
            effectList.textContent = '';
            if (Array.isArray(definition.effects)) {
                definition.effects.forEach(function(effect) {
                    addEffect(effect);
                });
            }
            updateApplicationMode();
        }

        /**
         * 切换可视化与兼容编辑模式 / Switches between builder and compatibility modes
         *
         * @param {string} mode 目标模式 / Target mode
         * @param {boolean} convert 是否转换当前内容 / Whether to convert current content
         * @returns {boolean} 是否切换成功 / Whether switching succeeded
         */
        function setEditorMode(mode, convert) {
            if (mode === 'legacy' && !legacyAllowedForCurrentCard) {
                showDefinitionErrors([
                    '旧JSON兼容模式只允许维护原有旧格式技能'
                    + ' / Legacy JSON mode may only maintain an existing legacy skill'
                ]);
                return false;
            }
            if (mode === 'legacy' && convert && definitionMode.value === 'builder') {
                const serialized = serializeBuilder();
                if (serialized.errors.length > 0) {
                    showDefinitionErrors(serialized.errors);
                    return false;
                }
                effectJson.value = JSON.stringify(
                    serialized.definition,
                    null,
                    2
                );
            }
            if (mode === 'builder' && convert
                && definitionMode.value === 'legacy') {
                let parsed;
                try {
                    parsed = JSON.parse(effectJson.value);
                } catch (error) {
                    showDefinitionErrors([
                        'JSON语法无效，无法切换到组合器 / Invalid JSON; cannot switch to the builder'
                    ]);
                    return false;
                }
                if (!parsed
                    || Array.isArray(parsed)
                    || parsed.schema_version !== 2) {
                    showDefinitionErrors([
                        '旧平面JSON不能自动转换，请继续使用兼容模式'
                        + ' / Legacy flat JSON cannot be converted automatically; continue in compatibility mode'
                    ]);
                    return false;
                }
                loadStructuredDefinition(parsed);
            }

            definitionMode.value = mode;
            const builder = mode === 'builder';
            builderPanel.hidden = !builder;
            legacyPanel.hidden = builder;
            effectJson.required = !builder;
            builderModeButton.classList.toggle('active', builder);
            legacyModeButton.classList.toggle('active', !builder);
            builderModeButton.setAttribute(
                'aria-selected',
                builder ? 'true' : 'false'
            );
            legacyModeButton.setAttribute(
                'aria-selected',
                builder ? 'false' : 'true'
            );
            showDefinitionErrors([]);
            return true;
        }

        /**
         * 更新旧格式兼容入口可用性 / Updates availability of the legacy compatibility entry
         *
         * @returns {void}
         */
        function updateLegacyModeAvailability() {
            legacyModeButton.disabled = !legacyAllowedForCurrentCard;
            legacyModeButton.title = legacyAllowedForCurrentCard
                ? '维护既有旧格式定义 / Maintain the existing legacy definition'
                : '仅既有旧格式技能可用 / Available only for existing legacy skills';
        }

        /**
         * 渲染所有尚未实现的占位机制 / Renders every unavailable placeholder mechanism
         *
         * @returns {void}
         */
        function renderPlaceholderCatalog() {
            placeholderList.textContent = '';
            Object.keys(catalogData.mechanisms).forEach(function(code) {
                const definition = catalogData.mechanisms[code];
                if (definition.status !== 'placeholder') {
                    return;
                }
                const item = document.createElement('div');
                item.className = 'placeholder-item';
                const title = document.createElement('strong');
                title.textContent = definition.label
                    + ' / ' + definition.label_en;
                const reason = document.createElement('span');
                reason.textContent = definition.description
                    + ' / ' + definition.description_en;
                const mechanismCode = document.createElement('span');
                mechanismCode.className = 'mechanism-code';
                mechanismCode.textContent = code;
                item.appendChild(title);
                item.appendChild(reason);
                item.appendChild(document.createElement('br'));
                item.appendChild(mechanismCode);
                placeholderList.appendChild(item);
            });
        }

        /**
         * 重置公共表单字段 / Resets shared form fields
         *
         * @returns {void}
         */
        function resetFormFields() {
            form.reset();
            document.getElementById('formAction').value = 'create_skill';
            document.getElementById('cardId').value = '';
            document.getElementById('cardRarity').value = 'B';
            document.getElementById('cardElement').value = '亮晶晶';
            activationType.value = 'passive';
            document.getElementById('cardCategory').value = 'internal';
            document.getElementById('baseCooldown').value = '0';
            document.getElementById('maxLevel').value = '5';
            document.getElementById('isActive').checked = true;
            includeCooldown.checked = false;
            applicationMode.value = 'continuous';
            definitionMode.value = 'builder';
            effectJson.value = '';
            effectJson.required = false;
            showDefinitionErrors([]);
        }

        /**
         * 打开已重置的创建表单 / Opens a reset create form
         *
         * @returns {void}
         */
        function openCreateModal() {
            const generation = ++modalGeneration;
            legacyAllowedForCurrentCard = false;
            updateLegacyModeAvailability();
            resetFormFields();
            document.getElementById('modalTitle').textContent
                = '创建技能卡 / Create skill card';
            modal.style.display = 'block';
            builderLoading.hidden = false;
            saveSkillButton.disabled = true;
            builderPanel.hidden = true;
            legacyPanel.hidden = true;

            ensureCatalog().then(function() {
                if (generation !== modalGeneration) {
                    return;
                }
                builderLoading.hidden = true;
                saveSkillButton.disabled = false;
                initializeValueEditor(cooldownValueEditor, 0);
                initializeValueEditor(durationValueEditor, 60);
                effectList.textContent = '';
                updateApplicationMode();
                addEffect(null);
                setEditorMode('builder', false);
            }).catch(function(error) {
                if (generation !== modalGeneration) {
                    return;
                }
                builderLoading.hidden = true;
                saveSkillButton.disabled = true;
                showDefinitionErrors([
                    error.message
                        || '无法读取机制目录 / Unable to load mechanism catalog'
                ]);
            });
        }

        /**
         * 从受权限保护的接口载入编辑数据 / Loads edit data from permission-protected endpoints
         *
         * @param {string} cardId 技能卡ID / Skill-card ID
         * @returns {void}
         */
        function openEditModal(cardId) {
            const generation = ++modalGeneration;
            legacyAllowedForCurrentCard = false;
            updateLegacyModeAvailability();
            resetFormFields();
            document.getElementById('modalTitle').textContent
                = '编辑技能卡 / Edit skill card';
            modal.style.display = 'block';
            builderLoading.hidden = false;
            saveSkillButton.disabled = true;
            builderPanel.hidden = true;
            legacyPanel.hidden = true;

            Promise.all([
                ensureCatalog(),
                requestJson(
                    form.getAttribute('data-skill-api')
                    + '?card_id=' + encodeURIComponent(cardId)
                )
            ]).then(function(results) {
                if (generation !== modalGeneration) {
                    return;
                }
                const card = results[1].card;
                document.getElementById('formAction').value = 'update_skill';
                document.getElementById('cardId').value = String(card.card_id);
                document.getElementById('cardCode').value = card.card_code;
                document.getElementById('cardName').value = card.name;
                document.getElementById('cardDescription').value = card.description;
                document.getElementById('cardRarity').value = card.rarity;
                document.getElementById('cardElement').value = card.element;
                activationType.value = card.activation_type;
                document.getElementById('cardCategory').value = card.category;
                document.getElementById('baseCooldown').value
                    = String(card.base_cooldown);
                document.getElementById('maxLevel').value
                    = String(card.max_level);
                document.getElementById('isActive').checked
                    = Number(card.is_active) === 1;
                effectJson.value = JSON.stringify(card.effect, null, 2);

                builderLoading.hidden = true;
                saveSkillButton.disabled = false;
                if (card.effect
                    && !Array.isArray(card.effect)
                    && card.effect.schema_version === 2) {
                    loadStructuredDefinition(card.effect);
                    legacyAllowedForCurrentCard = false;
                    updateLegacyModeAvailability();
                    setEditorMode('builder', false);
                } else {
                    legacyAllowedForCurrentCard = true;
                    updateLegacyModeAvailability();
                    initializeValueEditor(cooldownValueEditor, 0);
                    initializeValueEditor(durationValueEditor, 60);
                    effectList.textContent = '';
                    setEditorMode('legacy', false);
                }
            }).catch(function(error) {
                if (generation !== modalGeneration) {
                    return;
                }
                builderLoading.hidden = true;
                saveSkillButton.disabled = true;
                showDefinitionErrors([
                    error.message
                        || '获取技能卡信息失败 / Failed to load skill-card details'
                ]);
            });
        }

        /**
         * 关闭编辑弹窗 / Closes the editor modal
         *
         * @returns {void}
         */
        function closeModal() {
            modalGeneration++;
            modal.style.display = 'none';
        }

        builderModeButton.addEventListener('click', function() {
            if (catalogData) {
                setEditorMode('builder', true);
            }
        });
        legacyModeButton.addEventListener('click', function() {
            if (catalogData) {
                setEditorMode('legacy', true);
            }
        });
        activationType.addEventListener('change', updateApplicationMode);
        applicationMode.addEventListener('change', updateApplicationMode);
        includeCooldown.addEventListener('change', updateApplicationMode);
        addEffectButton.addEventListener('click', function() {
            addEffect(null);
        });
        document.getElementById('maxLevel').addEventListener(
            'change',
            function() {
                Array.prototype.forEach.call(
                    document.querySelectorAll(
                        '#definitionBuilderPanel .value-editor'
                    ),
                    function(editor) {
                        const body = editor.querySelector('.value-editor-body');
                        const mode = editor.querySelector('.value-mode');
                        if (body && mode && mode.value !== 'fixed') {
                            const help = body.querySelector('.definition-help');
                            if (help) {
                                help.textContent = '项数须正好等于当前最高等级 Lv.'
                                    + getMaximumLevel()
                                    + '。 / Must contain exactly max level Lv.'
                                    + getMaximumLevel() + ' entries.';
                            }
                        }
                    }
                );
            }
        );

        form.addEventListener('submit', function(event) {
            const errors = [];
            if (definitionMode.value === 'builder') {
                if (!catalogData) {
                    errors.push('机制目录尚未载入 / Mechanism catalog is not loaded');
                } else {
                    const serialized = serializeBuilder();
                    Array.prototype.push.apply(errors, serialized.errors);
                    effectJson.value = JSON.stringify(
                        serialized.definition
                    );
                }
            } else {
                try {
                    const parsed = JSON.parse(effectJson.value);
                    if (!parsed || Array.isArray(parsed)
                        || typeof parsed !== 'object') {
                        errors.push('效果JSON必须是对象 / Effect JSON must be an object');
                    }
                } catch (error) {
                    errors.push('效果JSON语法无效 / Effect JSON syntax is invalid');
                }
            }

            const byteLength = window.TextEncoder
                ? new TextEncoder().encode(effectJson.value).length
                : unescape(encodeURIComponent(effectJson.value)).length;
            if (byteLength < 1 || byteLength > 60000) {
                errors.push(
                    '效果JSON须为1至60000字节'
                    + ' / Effect JSON must contain 1 to 60000 bytes'
                );
            }
            if (errors.length > 0) {
                event.preventDefault();
                showDefinitionErrors(errors);
                definitionErrors.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }
        });

        if (createButton) {
            createButton.addEventListener('click', openCreateModal);
        }
        closeButton.addEventListener('click', closeModal);
        cancelButton.addEventListener('click', closeModal);
        document.querySelectorAll('.edit-card-button').forEach(function(button) {
            button.addEventListener('click', function() {
                openEditModal(button.getAttribute('data-card-id'));
            });
        });
        document.querySelectorAll('.disable-card-form').forEach(
            function(disableForm) {
                disableForm.addEventListener('submit', function(event) {
                    if (!window.confirm(
                        '确定停用这张技能卡吗？玩家已持有和已装备的数据会保留。'
                        + ' / Disable this card? Existing inventory and equipment remain.'
                    )) {
                        event.preventDefault();
                    }
                });
            }
        );
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    });
}());
