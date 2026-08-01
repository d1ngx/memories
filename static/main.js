kodReady.push(function(){
	LNG.set(jsonDecode(urlDecode("{{LNG}}")));
	if ("{{config.menuAdd}}" !== "1") return;
	Events.bind('main.menu.loadBefore', function(listData){
		listData['memories'] = {
			name: LNG['memories.meta.name'],
			url: '{{pluginApi}}',
			target: 'inline',
			menuAdd: '1',
			icon: 'ri-gallery-fill bg-blue-6'
		};
	});
	Router.mapIframe({page:'memories', title:LNG['memories.meta.name'], url:'{{pluginApi}}', ignoreLogin:false});
});
