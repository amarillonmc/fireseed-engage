// 种火集结号 - 地图JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // 获取地图中心坐标
    const urlParams = new URLSearchParams(window.location.search);
    const isSelectingTarget = urlParams.get('select_target') === '1';
    const selectedArmyValue = Number(urlParams.get('army_id'));
    const selectedArmyId = Number.isInteger(selectedArmyValue) && selectedArmyValue > 0
        ? selectedArmyValue
        : 0;
    const requestedX = urlParams.has('x') ? Number(urlParams.get('x')) : NaN;
    const requestedY = urlParams.has('y') ? Number(urlParams.get('y')) : NaN;
    let centerX = Number.isInteger(requestedX) ? requestedX : Math.floor(MAP_WIDTH / 2);
    let centerY = Number.isInteger(requestedY) ? requestedY : Math.floor(MAP_HEIGHT / 2);
    const explorationArmySelect = document.getElementById('explore-army');
    
    // 确保坐标在地图范围内
    centerX = Math.max(0, Math.min(MAP_WIDTH - 1, centerX));
    centerY = Math.max(0, Math.min(MAP_HEIGHT - 1, centerY));
    
    // 地图视图范围
    const viewRadius = 5;
    
    // 加载地图
    loadMap(centerX, centerY);
    
    // 探索按钮点击事件
    document.getElementById('explore-btn').addEventListener('click', function() {
        exploreMap(centerX, centerY);
    });
    
    // 刷新按钮点击事件
    document.getElementById('refresh-btn').addEventListener('click', function() {
        loadMap(centerX, centerY);
    });
    
    // 导航按钮点击事件
    const navButtons = document.querySelectorAll('.map-navigation button[data-dx]');
    navButtons.forEach(button => {
        button.addEventListener('click', function() {
            const dx = parseInt(this.getAttribute('data-dx'));
            const dy = parseInt(this.getAttribute('data-dy'));
            
            const newX = centerX + dx;
            const newY = centerY + dy;
            
            // 确保新坐标在地图范围内
            if (newX >= 0 && newX < MAP_WIDTH && newY >= 0 && newY < MAP_HEIGHT) {
                window.location.href = `map.php?x=${newX}&y=${newY}`;
            }
        });
    });
    
    // 中心按钮点击事件
    document.getElementById('nav-center').addEventListener('click', function() {
        // 获取用户主城坐标
        fetch('api/get_main_city.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `map.php?x=${data.x}&y=${data.y}`;
                } else {
                    showNotification(data.message);
                }
            })
            .catch(error => console.error('Error getting main city:', error));
    });
    
    // 搜索按钮点击事件
    document.getElementById('search-btn').addEventListener('click', function() {
        const x = Number(document.getElementById('search-x').value);
        const y = Number(document.getElementById('search-y').value);
        
        // 确保坐标在地图范围内
        if (Number.isInteger(x)
            && Number.isInteger(y)
            && x >= 0
            && x < MAP_WIDTH
            && y >= 0
            && y < MAP_HEIGHT) {
            window.location.href = `map.php?x=${x}&y=${y}`;
        } else {
            showNotification('坐标超出地图范围');
        }
    });
    
    // 加载地图
    function loadMap(x, y) {
        // 计算地图视图范围
        const startX = Math.max(0, x - viewRadius);
        const startY = Math.max(0, y - viewRadius);
        const endX = Math.min(MAP_WIDTH - 1, x + viewRadius);
        const endY = Math.min(MAP_HEIGHT - 1, y + viewRadius);
        
        // 获取地图数据
        fetch(`api/get_map.php?start_x=${startX}&start_y=${startY}&end_x=${endX}&end_y=${endY}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderMap(data.tiles, x, y);
                } else {
                    showNotification(data.message);
                }
            })
            .catch(error => console.error('Error loading map:', error));
    }
    
    // 渲染地图
    function renderMap(tiles, centerX, centerY) {
        const mapGrid = document.getElementById('map-grid');
        mapGrid.innerHTML = '';
        
        // 计算地图视图范围
        const startX = Math.max(0, centerX - viewRadius);
        const startY = Math.max(0, centerY - viewRadius);
        const endX = Math.min(MAP_WIDTH - 1, centerX + viewRadius);
        const endY = Math.min(MAP_HEIGHT - 1, centerY + viewRadius);
        
        // 创建二维数组存储地图数据
        const mapData = {};
        
        // 填充地图数据
        (Array.isArray(tiles) ? tiles : []).forEach(tile => {
            const tileX = normalizeCoordinate(tile && tile.x, MAP_WIDTH);
            const tileY = normalizeCoordinate(tile && tile.y, MAP_HEIGHT);
            if (tileX === null || tileY === null) {
                return;
            }
            const normalizedTile = Object.assign({}, tile, {
                x: tileX,
                y: tileY,
                type: normalizeTileType(tile.type),
                subtype: normalizeTileSubtype(tile.subtype),
                is_visible: tile.is_visible === true || Number(tile.is_visible) === 1,
                garrison_total: normalizeNonNegativeInteger(tile.garrison_total),
                garrison_units: normalizeGarrisonUnits(tile.garrison_units)
            });
            const key = `${tileX},${tileY}`;
            mapData[key] = normalizedTile;
        });
        
        // 渲染地图格子
        for (let y = startY; y <= endY; y++) {
            for (let x = startX; x <= endX; x++) {
                const key = `${x},${y}`;
                const tile = mapData[key];
                
                const cell = document.createElement('div');
                cell.className = 'map-cell';
                cell.setAttribute('data-x', x);
                cell.setAttribute('data-y', y);
                
                // 如果是当前中心点，添加current类
                if (x === centerX && y === centerY) {
                    cell.classList.add('current');
                }
                
                // 如果地图格子存在且可见
                if (tile && tile.is_visible) {
                    cell.classList.add(tile.type);
                    
                    if (tile.subtype) {
                        cell.classList.add(tile.subtype);
                    }
                    
                    // 添加图标
                    const icon = document.createElement('div');
                    icon.className = 'map-cell-icon';
                    
                    switch (tile.type) {
                        case 'empty':
                            icon.textContent = '🏞️';
                            break;
                        case 'resource':
                            switch (tile.subtype) {
                                case 'bright':
                                    icon.textContent = '💎';
                                    break;
                                case 'warm':
                                    icon.textContent = '🔥';
                                    break;
                                case 'cold':
                                    icon.textContent = '❄️';
                                    break;
                                case 'green':
                                    icon.textContent = '🌿';
                                    break;
                                case 'day':
                                    icon.textContent = '☀️';
                                    break;
                                case 'night':
                                    icon.textContent = '🌙';
                                    break;
                                default:
                                    icon.textContent = '💎';
                            }
                            break;
                        case 'npc_fort':
                            icon.textContent = '🏰';
                            break;
                        case 'player_city':
                            icon.textContent = '🏙️';
                            break;
                        case 'special':
                            if (tile.subtype === 'silver_hole') {
                                icon.textContent = '🌟';
                            } else {
                                icon.textContent = '🔮';
                            }
                            break;
                        default:
                            icon.textContent = '❓';
                    }
                    
                    cell.appendChild(icon);
                    
                    // 添加坐标
                    const coords = document.createElement('div');
                    coords.className = 'map-cell-coords';
                    coords.textContent = `(${x}, ${y})`;
                    cell.appendChild(coords);
                    
                    // 选点模式只返回坐标，不在GET请求中执行移动 / Target-selection mode returns coordinates without mutating through GET
                    cell.addEventListener('click', function() {
                        if (isSelectingTarget && selectedArmyId > 0) {
                            window.location.href = `move_army.php?army_id=${selectedArmyId}&target_x=${tile.x}&target_y=${tile.y}`;
                            return;
                        }
                        showTileInfo(tile);
                    });
                } else {
                    // 未探索的格子
                    cell.classList.add('not-visible');
                    
                    // 添加坐标
                    const coords = document.createElement('div');
                    coords.className = 'map-cell-coords';
                    coords.textContent = `(${x}, ${y})`;
                    cell.appendChild(coords);
                }
                
                mapGrid.appendChild(cell);
            }
        }
    }
    
    // 显示地图格子信息
    function showTileInfo(tile) {
        const mapInfo = document.getElementById('map-info');
        
        if (!tile || !tile.is_visible) {
            const hiddenX = tile && Number.isFinite(Number(tile.x)) ? Math.trunc(Number(tile.x)) : 0;
            const hiddenY = tile && Number.isFinite(Number(tile.y)) ? Math.trunc(Number(tile.y)) : 0;
            mapInfo.innerHTML = `
                <h3>未探索区域</h3>
                <p>该区域尚未被探索，无法获取详细信息。</p>
                <div class="map-actions">
                    <button id="explore-tile-btn" data-x="${hiddenX}" data-y="${hiddenY}">探索</button>
                </div>
            `;
            
            // 添加探索按钮点击事件
            document.getElementById('explore-tile-btn').addEventListener('click', function() {
                const x = normalizeCoordinate(Number(this.getAttribute('data-x')), MAP_WIDTH);
                const y = normalizeCoordinate(Number(this.getAttribute('data-y')), MAP_HEIGHT);
                if (x !== null && y !== null) {
                    exploreMap(x, y);
                }
            });
            
            return;
        }

        // 数据库文本必须在写入innerHTML前转义，坐标与数值必须规范化 / Escape database text before innerHTML and normalize all numeric values
        const tileX = Number.isFinite(Number(tile.x)) ? Math.trunc(Number(tile.x)) : 0;
        const tileY = Number.isFinite(Number(tile.y)) ? Math.trunc(Number(tile.y)) : 0;
        const tileOwnerId = Number.isFinite(Number(tile.owner_id)) ? Math.trunc(Number(tile.owner_id)) : 0;
        const tileCityId = Number.isFinite(Number(tile.city_id)) ? Math.trunc(Number(tile.city_id)) : 0;
        const resourceAmount = Number.isFinite(Number(tile.resource_amount)) ? Math.trunc(Number(tile.resource_amount)) : 0;
        const npcLevel = Number.isFinite(Number(tile.npc_level)) ? Math.trunc(Number(tile.npc_level)) : 0;
        const tileId = normalizePositiveInteger(tile.tile_id);
        const garrisonTotal = normalizeNonNegativeInteger(tile.garrison_total);
        const ownGarrison = tileOwnerId === USER_ID
            ? renderOwnGarrison(tile.garrison_units)
            : '';
        const safeName = escapeHtml(tile.name || '');
        const safeDescription = escapeHtml(tile.description || '');
        const safeOwnerName = escapeHtml(tile.owner_name || '');
        const safeForceOwnerName = escapeHtml(tile.force_owner_name || '');
        const forceOwnerId = Number.isFinite(Number(tile.force_owner_id))
            ? Math.trunc(Number(tile.force_owner_id))
            : 0;
        const sameForce = tile.same_force === true;
        const forceNotice = tileOwnerId > 0
            && forceOwnerId > 0
            && forceOwnerId !== tileOwnerId
            ? `<p>势力归属: ${safeForceOwnerName}</p>`
            : '';
        
        let infoHtml = `
            <h3>${safeName}</h3>
            <p>坐标: (${tileX}, ${tileY})</p>
            <p>${safeDescription}</p>
        `;
        
        // 根据地图格子类型添加额外信息
        switch (tile.type) {
            case 'empty':
                if (tileOwnerId > 0) {
                    infoHtml += `
                        <p>拥有者: ${safeOwnerName}</p>
                        ${forceNotice}
                        <p>驻军总量: ${garrisonTotal}</p>
                        ${ownGarrison}
                        <div class="map-actions">
                            ${tileOwnerId === USER_ID
                                ? `${tileId > 0 ? `<button id="territory-btn" data-tile-id="${tileId}">管理驻军</button>` : ''}
                                   ${garrisonTotal === 0 ? `<button id="abandon-btn" data-x="${tileX}" data-y="${tileY}">放弃</button>` : ''}`
                                : (sameForce
                                    ? '<span>同势力领地</span>'
                                    : `<button id="attack-btn" data-x="${tileX}" data-y="${tileY}">攻击</button>`)}
                        </div>
                    `;
                } else {
                    infoHtml += `
                        <div class="map-actions">
                            <button id="occupy-btn" data-x="${tileX}" data-y="${tileY}">占领</button>
                        </div>
                    `;
                }
                break;
            case 'resource':
                infoHtml += `
                    <p>资源类型: ${getResourceName(tile.subtype)}</p>
                    <p>资源数量: ${resourceAmount}</p>
                `;
                
                if (tileOwnerId > 0) {
                    infoHtml += `
                        <p>拥有者: ${safeOwnerName}</p>
                        ${forceNotice}
                        <p>驻军总量: ${garrisonTotal}</p>
                        ${ownGarrison}
                        <div class="map-actions">
                            ${tileOwnerId === USER_ID
                                ? `${tileId > 0 ? `<button id="territory-btn" data-tile-id="${tileId}">管理驻军</button>` : ''}
                                   ${garrisonTotal === 0 ? `<button id="abandon-btn" data-x="${tileX}" data-y="${tileY}">放弃</button>` : ''}`
                                : (sameForce
                                    ? '<span>同势力领地</span>'
                                    : `<button id="attack-btn" data-x="${tileX}" data-y="${tileY}">攻击</button>`)}
                        </div>
                    `;
                } else {
                    infoHtml += `
                        <div class="map-actions">
                            <button id="occupy-btn" data-x="${tileX}" data-y="${tileY}">占领</button>
                        </div>
                    `;
                }
                break;
            case 'npc_fort':
                infoHtml += `
                    <p>等级: ${npcLevel}</p>
                    ${tileOwnerId > 0 ? `<p>拥有者: ${safeOwnerName}</p>${forceNotice}` : ''}
                    <div class="map-actions">
                        ${sameForce
                            ? '<span>同势力据点</span>'
                            : (tile.subtype && tile.subtype.indexOf('gateway_') === 0
                            ? '<button id="season-btn">前往赛季战</button>'
                            : `<button id="attack-btn" data-x="${tileX}" data-y="${tileY}">攻击</button>`)}
                    </div>
                `;
                break;
            case 'player_city':
                infoHtml += `
                    <p>拥有者: ${safeOwnerName}</p>
                    ${forceNotice}
                    <div class="map-actions">
                        ${tileOwnerId === USER_ID
                            ? `<button id="enter-btn" data-city-id="${tileCityId}">进入</button>`
                            : (sameForce
                                ? '<span>同势力城池</span>'
                                : `<button id="attack-btn" data-x="${tileX}" data-y="${tileY}">攻击</button>`)}
                    </div>
                `;
                break;
            case 'special':
                if (tile.subtype === 'silver_hole') {
                    infoHtml += `
                        <p>银白之孔是游戏的最终目标，占领并持有30天即可获得胜利。</p>
                        <div class="map-actions">
                            <button id="season-btn">前往赛季战</button>
                        </div>
                    `;
                }
                break;
        }
        
        mapInfo.innerHTML = infoHtml;
        
        // 添加按钮点击事件
        if (document.getElementById('occupy-btn')) {
            document.getElementById('occupy-btn').addEventListener('click', function() {
                const x = normalizeCoordinate(Number(this.getAttribute('data-x')), MAP_WIDTH);
                const y = normalizeCoordinate(Number(this.getAttribute('data-y')), MAP_HEIGHT);
                if (x !== null && y !== null) {
                    occupyTile(x, y);
                }
            });
        }
        
        if (document.getElementById('abandon-btn')) {
            document.getElementById('abandon-btn').addEventListener('click', function() {
                const x = normalizeCoordinate(Number(this.getAttribute('data-x')), MAP_WIDTH);
                const y = normalizeCoordinate(Number(this.getAttribute('data-y')), MAP_HEIGHT);
                if (x !== null && y !== null) {
                    abandonTile(x, y);
                }
            });
        }
        
        if (document.getElementById('attack-btn')) {
            document.getElementById('attack-btn').addEventListener('click', function() {
                const x = normalizeCoordinate(Number(this.getAttribute('data-x')), MAP_WIDTH);
                const y = normalizeCoordinate(Number(this.getAttribute('data-y')), MAP_HEIGHT);
                if (x !== null && y !== null) {
                    attackTile(x, y);
                }
            });
        }
        
        if (document.getElementById('enter-btn')) {
            document.getElementById('enter-btn').addEventListener('click', function() {
                const cityId = Number(this.getAttribute('data-city-id'));
                if (Number.isInteger(cityId) && cityId > 0) {
                    window.location.href = `internal.php?city_id=${cityId}`;
                }
            });
        }

        if (document.getElementById('territory-btn')) {
            document.getElementById('territory-btn').addEventListener('click', function() {
                const managedTileId = normalizePositiveInteger(
                    this.getAttribute('data-tile-id')
                );
                if (managedTileId > 0) {
                    window.location.href = `territory.php#territory-${managedTileId}`;
                }
            });
        }

        if (document.getElementById('season-btn')) {
            document.getElementById('season-btn').addEventListener('click', function() {
                window.location.href = 'season.php';
            });
        }
    }
    
    // 探索地图
    function exploreMap(x, y) {
        const armyId = explorationArmySelect
            ? Number(explorationArmySelect.value)
            : 0;
        postForm('api/explore_map.php', {
            x: x,
            y: y,
            army_id: Number.isInteger(armyId) && armyId > 0 ? armyId : 0
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`探索成功，发现了${data.discovered_tiles.length}个新地点`);
                    loadMap(x, y);
                } else {
                    showNotification(data.message);
                }
            })
            .catch(error => console.error('Error exploring map:', error));
    }
    
    // 占领地图格子
    function occupyTile(x, y) {
        postForm('api/occupy_tile.php', {x: x, y: y})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('占领成功');
                    loadMap(centerX, centerY);
                } else {
                    showNotification(data.message);
                }
            })
            .catch(error => console.error('Error occupying tile:', error));
    }
    
    // 放弃地图格子
    function abandonTile(x, y) {
        if (confirm('确定要放弃这个地点吗？')) {
            postForm('api/abandon_tile.php', {x: x, y: y})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('放弃成功');
                        loadMap(centerX, centerY);
                    } else {
                        showNotification(data.message);
                    }
                })
                .catch(error => console.error('Error abandoning tile:', error));
        }
    }
    
    // 攻击地图格子
    function attackTile(x, y) {
        // 跳转到军队选择页面
        window.location.href = `army_select.php?target_x=${x}&target_y=${y}`;
    }

    // 统一提交带CSRF令牌的表单请求 / Submit state-changing form requests with a CSRF token
    function postForm(url, values) {
        const body = new FormData();
        body.append('csrf_token', CSRF_TOKEN);
        Object.keys(values).forEach(key => body.append(key, values[key]));
        return fetch(url, {
            method: 'POST',
            body: body
        });
    }
    
    // 获取资源名称
    function getResourceName(type) {
        switch (type) {
            case 'bright':
                return '亮晶晶';
            case 'warm':
                return '暖洋洋';
            case 'cold':
                return '冷冰冰';
            case 'green':
                return '郁萌萌';
            case 'day':
                return '昼闪闪';
            case 'night':
                return '夜静静';
            default:
                return '未知资源';
        }
    }

    // 将己方驻军编成渲染为安全的固定词汇列表 / Render an owned garrison composition with safe, fixed vocabulary
    function renderOwnGarrison(units) {
        if (!Array.isArray(units) || units.length === 0) {
            return '<p>驻军编成: 无</p>';
        }

        const items = units.map(unit => {
            return `<li>${getSoldierName(unit.soldier_type)} Lv.${unit.level}：${unit.quantity}</li>`;
        }).join('');
        return `<div><strong>驻军编成:</strong><ul>${items}</ul></div>`;
    }

    // 规范化服务端驻军编成并丢弃未知值 / Normalize server garrison units and discard unknown values
    function normalizeGarrisonUnits(units) {
        const validTypes = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
        if (!Array.isArray(units)) {
            return [];
        }

        return units.reduce((normalized, unit) => {
            const soldierType = String(unit && unit.soldier_type || '');
            const level = normalizePositiveInteger(unit && unit.level);
            const quantity = normalizePositiveInteger(unit && unit.quantity);
            if (validTypes.indexOf(soldierType) >= 0 && level > 0 && quantity > 0) {
                normalized.push({
                    soldier_type: soldierType,
                    level: level,
                    quantity: quantity
                });
            }
            return normalized;
        }, []);
    }

    // 获取固定的兵种名称 / Get a fixed soldier-type label
    function getSoldierName(type) {
        const names = {
            pawn: '兵卒',
            knight: '骑士',
            rook: '城壁',
            bishop: '主教',
            golem: '锤子兵',
            scout: '侦察兵'
        };
        return names[type] || '未知士兵';
    }

    // 转义动态HTML文本 / Escape dynamic HTML text
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // 将服务端坐标规范化为有效整数 / Normalize a server coordinate to a valid integer
    function normalizeCoordinate(value, upperBound) {
        const numericValue = Number(value);
        if (!Number.isInteger(numericValue)
            || numericValue < 0
            || numericValue >= upperBound) {
            return null;
        }
        return numericValue;
    }

    // 规范化正整数 / Normalize a positive integer
    function normalizePositiveInteger(value) {
        const numericValue = Number(value);
        return Number.isInteger(numericValue) && numericValue > 0
            ? numericValue
            : 0;
    }

    // 规范化非负整数 / Normalize a non-negative integer
    function normalizeNonNegativeInteger(value) {
        const numericValue = Number(value);
        return Number.isInteger(numericValue) && numericValue >= 0
            ? numericValue
            : 0;
    }

    // 地图主类型只允许已知CSS类 / Allow only known map-type CSS classes
    function normalizeTileType(value) {
        const type = String(value || '');
        return ['empty', 'resource', 'npc_fort', 'player_city', 'special'].indexOf(type) >= 0
            ? type
            : 'empty';
    }

    // 地图子类型只允许资源、据点和世界地点编码 / Allow only resource, fort, and world-site subtype codes
    function normalizeTileSubtype(value) {
        const subtype = String(value || '');
        return /^(bright|warm|cold|green|day|night|data_fort|silver_hole|gateway_[a-z0-9_]+)$/.test(subtype)
            ? subtype
            : '';
    }
});

// 地图常量
const MAP_WIDTH = 512;
const MAP_HEIGHT = 512;
const USER_ID = Number(document.querySelector('meta[name="user-id"]').getAttribute('content'));
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
