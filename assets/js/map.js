// 种火集结号 - 地图JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // 获取地图中心坐标
    const urlParams = new URLSearchParams(window.location.search);
    let centerX = parseInt(urlParams.get('x')) || Math.floor(MAP_WIDTH / 2);
    let centerY = parseInt(urlParams.get('y')) || Math.floor(MAP_HEIGHT / 2);
    
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
        const x = parseInt(document.getElementById('search-x').value);
        const y = parseInt(document.getElementById('search-y').value);
        
        // 确保坐标在地图范围内
        if (x >= 0 && x < MAP_WIDTH && y >= 0 && y < MAP_HEIGHT) {
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
        tiles.forEach(tile => {
            const key = `${tile.x},${tile.y}`;
            mapData[key] = tile;
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
                    
                    // 添加点击事件
                    cell.addEventListener('click', function() {
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
            mapInfo.innerHTML = `
                <h3>未探索区域</h3>
                <p>该区域尚未被探索，无法获取详细信息。</p>
                <div class="map-actions">
                    <button id="explore-tile-btn" data-x="${tile ? tile.x : 0}" data-y="${tile ? tile.y : 0}">探索</button>
                </div>
            `;
            
            // 添加探索按钮点击事件
            document.getElementById('explore-tile-btn').addEventListener('click', function() {
                const x = parseInt(this.getAttribute('data-x'));
                const y = parseInt(this.getAttribute('data-y'));
                exploreMap(x, y);
            });
            
            return;
        }
        
        let infoHtml = `
            <h3>${tile.name}</h3>
            <p>坐标: (${tile.x}, ${tile.y})</p>
            <p>${tile.description}</p>
        `;
        
        // 根据地图格子类型添加额外信息
        switch (tile.type) {
            case 'empty':
                infoHtml += `
                    <div class="map-actions">
                        <button id="occupy-btn" data-x="${tile.x}" data-y="${tile.y}">占领</button>
                    </div>
                `;
                break;
            case 'resource':
                infoHtml += `
                    <p>资源类型: ${getResourceName(tile.subtype)}</p>
                    <p>资源数量: ${tile.resource_amount}</p>
                `;
                
                if (tile.owner_id) {
                    infoHtml += `
                        <p>拥有者: ${tile.owner_name}</p>
                        <div class="map-actions">
                            ${tile.owner_id == USER_ID ? `<button id="abandon-btn" data-x="${tile.x}" data-y="${tile.y}">放弃</button>` : ''}
                        </div>
                    `;
                } else {
                    infoHtml += `
                        <div class="map-actions">
                            <button id="occupy-btn" data-x="${tile.x}" data-y="${tile.y}">占领</button>
                        </div>
                    `;
                }
                break;
            case 'npc_fort':
                infoHtml += `
                    <p>等级: ${tile.npc_level}</p>
                    <div class="map-actions">
                        <button id="attack-btn" data-x="${tile.x}" data-y="${tile.y}">攻击</button>
                    </div>
                `;
                break;
            case 'player_city':
                infoHtml += `
                    <p>拥有者: ${tile.owner_name}</p>
                    <div class="map-actions">
                        ${tile.owner_id == USER_ID ? `<button id="enter-btn" data-city-id="${tile.city_id}">进入</button>` : `<button id="attack-btn" data-x="${tile.x}" data-y="${tile.y}">攻击</button>`}
                    </div>
                `;
                break;
            case 'special':
                if (tile.subtype === 'silver_hole') {
                    infoHtml += `
                        <p>银白之孔是游戏的最终目标，占领并持有30天即可获得胜利。</p>
                        <div class="map-actions">
                            <button id="occupy-btn" data-x="${tile.x}" data-y="${tile.y}">占领</button>
                        </div>
                    `;
                }
                break;
        }
        
        mapInfo.innerHTML = infoHtml;
        
        // 添加按钮点击事件
        if (document.getElementById('occupy-btn')) {
            document.getElementById('occupy-btn').addEventListener('click', function() {
                const x = parseInt(this.getAttribute('data-x'));
                const y = parseInt(this.getAttribute('data-y'));
                occupyTile(x, y);
            });
        }
        
        if (document.getElementById('abandon-btn')) {
            document.getElementById('abandon-btn').addEventListener('click', function() {
                const x = parseInt(this.getAttribute('data-x'));
                const y = parseInt(this.getAttribute('data-y'));
                abandonTile(x, y);
            });
        }
        
        if (document.getElementById('attack-btn')) {
            document.getElementById('attack-btn').addEventListener('click', function() {
                const x = parseInt(this.getAttribute('data-x'));
                const y = parseInt(this.getAttribute('data-y'));
                attackTile(x, y);
            });
        }
        
        if (document.getElementById('enter-btn')) {
            document.getElementById('enter-btn').addEventListener('click', function() {
                const cityId = parseInt(this.getAttribute('data-city-id'));
                window.location.href = `city.php?id=${cityId}`;
            });
        }
    }
    
    // 探索地图
    function exploreMap(x, y) {
        fetch(`api/explore_map.php?x=${x}&y=${y}`)
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
        fetch(`api/occupy_tile.php?x=${x}&y=${y}`)
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
            fetch(`api/abandon_tile.php?x=${x}&y=${y}`)
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
});

// 地图常量
const MAP_WIDTH = 512;
const MAP_HEIGHT = 512;
const USER_ID = document.querySelector('meta[name="user-id"]').getAttribute('content');
