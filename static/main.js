kodReady.push(function(){
	LNG.set(jsonDecode(urlDecode("{{LNG}}")));
	Events.bind('main.menu.loadBefore', function(listData){
		listData['memories'] = {
			name: LNG['memories.meta.name'],
			url: '{{pluginApi}}',
			target: 'inline',
			subMenu: '{{config.menuSubMenu}}',
			menuAdd: '{{config.menuAdd}}',
			icon: 'ri-gallery-fill bg-blue-6'
		};
	});
	Router.mapIframe({page:'memories', title:LNG['memories.meta.name'], url:'{{pluginApi}}', ignoreLogin:false});
});
