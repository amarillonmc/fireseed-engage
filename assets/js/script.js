// 种火集结号 - 主脚本文件

document.addEventListener('DOMContentLoaded', function() {
    // 设施点击事件
    const facilities = document.querySelectorAll('.city-cell.facility');
    facilities.forEach(facility => {
        facility.addEventListener('click', function() {
            const facilityId = this.getAttribute('data-facility-id');
            window.location.href = 'facility.php?id=' + facilityId;
        });
    });
    
    // 空格子点击事件
    const emptyCells = document.querySelectorAll('.city-cell.empty');
    emptyCells.forEach(cell => {
        cell.addEventListener('click', function() {
            const x = this.getAttribute('data-x');
            const y = this.getAttribute('data-y');
            window.location.href = 'build.php?x=' + x + '&y=' + y;
        });
    });
    
    // 资源更新
    function updateResources() {
        fetch('api/update_resources.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新资源显示
                    document.querySelector('.bright-crystal .resource-value').textContent = numberFormat(data.resources.bright_crystal);
                    document.querySelector('.warm-crystal .resource-value').textContent = numberFormat(data.resources.warm_crystal);
                    document.querySelector('.cold-crystal .resource-value').textContent = numberFormat(data.resources.cold_crystal);
                    document.querySelector('.green-crystal .resource-value').textContent = numberFormat(data.resources.green_crystal);
                    document.querySelector('.day-crystal .resource-value').textContent = numberFormat(data.resources.day_crystal);
                    document.querySelector('.night-crystal .resource-value').textContent = numberFormat(data.resources.night_crystal);
                    
                    // 更新思考回路显示
                    document.querySelector('.circuit-points').textContent = `思考回路: ${data.circuit_points} / ${data.max_circuit_points}`;
                    
                    // 如果有城池产出了思考回路，显示通知
                    if (data.circuit_produced_cities && data.circuit_produced_cities.length > 0) {
                        data.circuit_produced_cities.forEach(city => {
                            showNotification(`${city.name} 产出了1点思考回路！`);
                        });
                    }
                }
            })
            .catch(error => console.error('Error updating resources:', error));
    }
    
    // 检查建筑完成情况
    function checkBuildingCompletion() {
        fetch('api/check_building_completion.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 处理完成的建造
                    if (data.completed_constructions && data.completed_constructions.length > 0) {
                        data.completed_constructions.forEach(facility => {
                            showNotification(`${facility.city_name} 的 ${facility.name} 建造完成！`);
                            
                            // 如果当前页面是城池页面，刷新城池视图
                            if (window.location.pathname.includes('city.php') || window.location.pathname.includes('index.php')) {
                                refreshCityView();
                            }
                        });
                    }
                    
                    // 处理完成的升级
                    if (data.completed_upgrades && data.completed_upgrades.length > 0) {
                        data.completed_upgrades.forEach(facility => {
                            showNotification(`${facility.city_name} 的 ${facility.name} 升级到 ${facility.level} 级！`);
                            
                            // 如果当前页面是城池页面，刷新城池视图
                            if (window.location.pathname.includes('city.php') || window.location.pathname.includes('index.php')) {
                                refreshCityView();
                            }
                        });
                    }
                }
            })
            .catch(error => console.error('Error checking building completion:', error));
    }
    
    // 检查训练完成情况
    function checkTrainingCompletion() {
        fetch('api/check_training_completion.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 处理完成的训练
                    if (data.completed_trainings && data.completed_trainings.length > 0) {
                        data.completed_trainings.forEach(soldier => {
                            showNotification(`${soldier.city_name} 的 ${soldier.quantity} 个 ${soldier.name} 训练完成！`);
                            
                            // 如果当前页面是兵营页面，刷新兵营视图
                            if (window.location.pathname.includes('barracks.php')) {
                                refreshBarracksView();
                            }
                        });
                    }
                }
            })
            .catch(error => console.error('Error checking training completion:', error));
    }
    
    // 刷新城池视图
    function refreshCityView() {
        // 获取当前城池ID
        const cityView = document.querySelector('.city-view');
        if (!cityView) return;
        
        const cityId = cityView.getAttribute('data-city-id');
        
        if (cityId) {
            fetch(`api/get_city_info.php?city_id=${cityId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 更新城池视图
                        updateCityView(data.city);
                    }
                })
                .catch(error => console.error('Error refreshing city view:', error));
        }
    }
    
    // 更新城池视图
    function updateCityView(city) {
        // 清空现有的城池网格
        const cityGrid = document.querySelector('.city-grid');
        if (!cityGrid) return;
        
        cityGrid.innerHTML = '';
        
        // 创建24x24的网格
        for (let y = 0; y < 24; y++) {
            const row = document.createElement('div');
            row.className = 'city-row';
            
            for (let x = 0; x < 24; x++) {
                let facilityFound = false;
                
                // 检查该位置是否有设施
                for (let i = 0; i < city.facilities.length; i++) {
                    const facility = city.facilities[i];
                    
                    if (facility.x_pos == x && facility.y_pos == y) {
                        const cell = document.createElement('div');
                        cell.className = `city-cell facility ${facility.type}`;
                        cell.setAttribute('data-facility-id', facility.facility_id);
                        
                        const facilityName = document.createElement('span');
                        facilityName.className = 'facility-name';
                        facilityName.textContent = facility.name;
                        
                        const facilityLevel = document.createElement('span');
                        facilityLevel.className = 'facility-level';
                        facilityLevel.textContent = `Lv.${facility.level}`;
                        
                        cell.appendChild(facilityName);
                        cell.appendChild(facilityLevel);
                        
                        // 添加点击事件
                        cell.addEventListener('click', function() {
                            window.location.href = `facility.php?id=${facility.facility_id}`;
                        });
                        
                        row.appendChild(cell);
                        facilityFound = true;
                        break;
                    }
                }
                
                // 如果没有设施，显示空格子
                if (!facilityFound) {
                    const cell = document.createElement('div');
                    cell.className = 'city-cell empty';
                    cell.setAttribute('data-x', x);
                    cell.setAttribute('data-y', y);
                    
                    // 添加点击事件
                    cell.addEventListener('click', function() {
                        window.location.href = `build.php?city_id=${city.city_id}&x=${x}&y=${y}`;
                    });
                    
                    row.appendChild(cell);
                }
            }
            
            cityGrid.appendChild(row);
        }
    }
    
    // 刷新兵营视图
    function refreshBarracksView() {
        // 获取当前城池ID
        const barracksView = document.querySelector('.barracks-view');
        if (!barracksView) return;
        
        const cityId = barracksView.getAttribute('data-city-id');
        
        if (cityId) {
            fetch(`api/get_soldiers.php?city_id=${cityId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 更新兵营视图
                        updateBarracksView(data.soldiers);
                    }
                })
                .catch(error => console.error('Error refreshing barracks view:', error));
        }
    }
    
    // 更新兵营视图
    function updateBarracksView(soldiers) {
        // 获取兵营表格
        const barracksTable = document.querySelector('.barracks-table tbody');
        if (!barracksTable) return;
        
        // 清空现有的行
        barracksTable.innerHTML = '';
        
        // 添加士兵行
        for (const type in soldiers) {
            const soldier = soldiers[type];
            
            const row = document.createElement('tr');
            
            // 士兵类型
            const typeCell = document.createElement('td');
            typeCell.textContent = getSoldierName(type);
            row.appendChild(typeCell);
            
            // 士兵等级
            const levelCell = document.createElement('td');
            levelCell.textContent = soldier.level;
            row.appendChild(levelCell);
            
            // 士兵数量
            const quantityCell = document.createElement('td');
            quantityCell.textContent = soldier.quantity;
            row.appendChild(quantityCell);
            
            // 训练中的数量
            const inTrainingCell = document.createElement('td');
            if (soldier.in_training > 0) {
                const trainingTime = new Date(soldier.training_complete_time);
                const now = new Date();
                
                if (trainingTime > now) {
                    const timeRemaining = Math.floor((trainingTime - now) / 1000); // 剩余秒数
                    inTrainingCell.textContent = `${soldier.in_training} (${formatTime(timeRemaining)})`;
                    
                    // 添加倒计时更新
                    const countdownInterval = setInterval(() => {
                        const now = new Date();
                        const timeRemaining = Math.floor((trainingTime - now) / 1000);
                        
                        if (timeRemaining <= 0) {
                            clearInterval(countdownInterval);
                            checkTrainingCompletion(); // 检查训练完成情况
                        } else {
                            inTrainingCell.textContent = `${soldier.in_training} (${formatTime(timeRemaining)})`;
                        }
                    }, 1000);
                } else {
                    inTrainingCell.textContent = `${soldier.in_training} (已完成)`;
                }
            } else {
                inTrainingCell.textContent = '0';
            }
            row.appendChild(inTrainingCell);
            
            // 训练按钮
            const actionCell = document.createElement('td');
            const trainButton = document.createElement('button');
            trainButton.className = 'train-button';
            trainButton.textContent = '训练';
            trainButton.addEventListener('click', function() {
                showTrainingDialog(type);
            });
            actionCell.appendChild(trainButton);
            row.appendChild(actionCell);
            
            barracksTable.appendChild(row);
        }
    }
    
    // 显示通知
    function showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'notification';
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        // 3秒后自动移除通知
        setTimeout(() => {
            notification.classList.add('fade-out');
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 500);
        }, 3000);
    }
    
    // 显示训练对话框
    function showTrainingDialog(soldierType) {
        // 创建对话框
        const dialog = document.createElement('div');
        dialog.className = 'dialog';
        
        // 对话框内容
        const dialogContent = document.createElement('div');
        dialogContent.className = 'dialog-content';
        
        // 标题
        const title = document.createElement('h3');
        title.textContent = `训练 ${getSoldierName(soldierType)}`;
        dialogContent.appendChild(title);
        
        // 数量输入
        const quantityLabel = document.createElement('label');
        quantityLabel.textContent = '数量:';
        dialogContent.appendChild(quantityLabel);
        
        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.min = 1;
        quantityInput.value = 1;
        dialogContent.appendChild(quantityInput);
        
        // 按钮容器
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'button-container';
        
        // 取消按钮
        const cancelButton = document.createElement('button');
        cancelButton.textContent = '取消';
        cancelButton.addEventListener('click', function() {
            document.body.removeChild(dialog);
        });
        buttonContainer.appendChild(cancelButton);
        
        // 确认按钮
        const confirmButton = document.createElement('button');
        confirmButton.textContent = '训练';
        confirmButton.addEventListener('click', function() {
            const quantity = parseInt(quantityInput.value);
            
            if (quantity > 0) {
                trainSoldiers(soldierType, quantity);
                document.body.removeChild(dialog);
            }
        });
        buttonContainer.appendChild(confirmButton);
        
        dialogContent.appendChild(buttonContainer);
        dialog.appendChild(dialogContent);
        
        // 添加到页面
        document.body.appendChild(dialog);
    }
    
    // 训练士兵
    function trainSoldiers(soldierType, quantity) {
        // 获取当前城池ID
        const barracksView = document.querySelector('.barracks-view');
        if (!barracksView) return;
        
        const cityId = barracksView.getAttribute('data-city-id');
        
        if (cityId) {
            const formData = new FormData();
            formData.append('city_id', cityId);
            formData.append('soldier_type', soldierType);
            formData.append('quantity', quantity);
            
            fetch('api/train_soldiers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`开始训练 ${quantity} 个 ${getSoldierName(soldierType)}`);
                    refreshBarracksView();
                } else {
                    showNotification(`训练失败: ${data.message}`);
                }
            })
            .catch(error => console.error('Error training soldiers:', error));
        }
    }
    
    // 获取士兵名称
    function getSoldierName(type) {
        switch (type) {
            case 'pawn':
                return '兵卒';
            case 'knight':
                return '骑士';
            case 'rook':
                return '城壁';
            case 'bishop':
                return '主教';
            case 'golem':
                return '锤子兵';
            case 'scout':
                return '侦察兵';
            default:
                return '未知士兵';
        }
    }
    
    // 格式化时间
    function formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    
    // 数字格式化
    function numberFormat(number) {
        return new Intl.NumberFormat().format(number);
    }
    
    // 每3秒更新一次资源
    setInterval(updateResources, 3000);
    
    // 每30秒检查一次建筑完成情况
    setInterval(checkBuildingCompletion, 30000);
    
    // 每30秒检查一次训练完成情况
    setInterval(checkTrainingCompletion, 30000);
    
    // 页面加载完成后立即更新一次资源
    updateResources();
    
    // 页面加载完成后立即检查一次建筑完成情况
    checkBuildingCompletion();
    
    // 页面加载完成后立即检查一次训练完成情况
    checkTrainingCompletion();
});
