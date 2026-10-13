const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
test.describe.configure({timeout:120000});
test.use({actionTimeout:15000});

test('private editor draft is resumed through the work center without creating a page',async({page,request,browser})=>{
    await login(page);await page.goto('/spaces/'+fixture().spaceKey+'/pages/create');
    const title='Private work center draft '+Date.now();await page.locator('[data-document-title]').fill(title);
    await page.locator('[data-visual-editor]').fill('Private unsaved publication source');await page.locator('[data-visual-editor]').press('ControlOrMeta+s');
    await expect.poll(async()=> (await api(request,'get','work?type=draft')).some(item=>item.title===title)).toBeTruthy();
    await page.locator('[data-editor-cancel]').click();await expect(page).toHaveURL('/spaces/'+fixture().spaceKey);await page.goto('/?type=draft');
    const row=page.locator('[data-work-item]').filter({hasText:title});await expect(row).toBeVisible();
    await row.getByRole('link',{name:'Resume draft',exact:true}).click();await expect(page.locator('[data-recovery-banner]')).toBeVisible();
    await page.locator('[data-restore-recovery]').click();await expect(page.locator('[data-document-title]')).toHaveValue(title);
    await expect(page.locator('[data-visual-editor]')).toContainText('Private unsaved publication source');
    const context=await browser.newContext(),other=await context.newPage();try{await login(other,1);await other.goto('/?type=draft');await expect(other.locator('[data-work-center]')).not.toContainText(title);}finally{await context.close();}
    expect((await api(request,'get','pages?space='+fixture().spaceKey)).some(item=>item.title===title)).toBeFalsy();
});
test('Polish collaboration views use the shared application theme and functional controls',async({page,request})=>{
    await login(page);await page.context().addCookies([{name:'openwiki_locale',value:'pl',url:'http://127.0.0.1:8765'}]);
    const document=await api(request,'post','pages',{space_id:fixture().spaceId,title:'Instrukcja odtworzenia',content_format:'visual',content_html:'<h2>Odtworzenie środowiska</h2><p>Sprawdź kopię zapasową przed rozpoczęciem odtwarzania.</p>',status:'published'});
    await page.goto('/spaces/'+fixture().spaceKey+'/pages/'+document.slug);await select(page,'kopię zapasową');
    await page.locator('[data-comment-form] [name=body]').fill('Potwierdź datę ostatniej poprawnej kopii.');await page.locator('[data-comment-form] [type=submit]').click();
    await expect(page.locator('.collaboration-thread')).toContainText('Potwierdź datę');
    for(const width of [390,768,1440]){
        await page.setViewportSize({width,height:900});await page.screenshot({path:'storage/temp/collaboration-polish-'+width+'.png'});
    }
    await page.locator('.collaboration-thread').getByRole('button',{name:'Utwórz zadanie',exact:true}).click();
    await page.locator('[data-task-dialog] [name=title]').fill('Zweryfikuj odtwarzanie');await page.locator('[data-task-details] summary').click();
    await page.locator('[data-task-dialog] [name=assignee_id]').selectOption(String(fixture().users[0].id));
    await page.locator('[data-task-save]').click();await expect(page.locator('[data-task-dialog]')).not.toBeVisible();
    await page.locator('[data-panel-close]').click();await page.locator('[data-tasks-list]').getByRole('button',{name:'Zweryfikuj odtwarzanie'}).click();
    await expect(page.locator('[data-task-source] a')).toHaveCount(2);await expect(page.locator('[data-task-save]')).toBeEnabled();await page.screenshot({path:'storage/temp/collaboration-polish-task.png'});
    await page.locator('[data-task-close]').click();await page.goto('/?type=task');await expect(page.locator('[data-work-center]')).toContainText('Zweryfikuj odtwarzanie');
    await page.screenshot({path:'storage/temp/collaboration-polish-center.png'});
});

test('working discussions and tasks stay private and revocation removes existing work and notifications',async({page,request,browser})=>{
    await login(page);
    const space=await api(request,'post','spaces',{name:'Private collaboration '+Date.now(),visibility:'public'});
    const document=await api(request,'post','pages',{space_id:space.id,title:'Visible publication',slug:'privacy',status:'published',content_html:'<p>Public procedure fragment.</p>'});
    const path='/spaces/'+space.key+'/pages/'+document.slug;
    await page.goto('/spaces/'+space.key+'/settings');await page.locator('[name=review_workflow]').selectOption('1');
    await page.getByRole('button',{name:'Zapisz ustawienia',exact:true}).click();
    await api(request,'patch','pages/'+document.id,{base_version:1,title:'Secret revision title',content_format:'visual',content_html:'<p>Secret procedure fragment.</p>',status:'draft'});
    await page.goto(path+'/review');await select(page,'Secret procedure','[data-review-content]');
    await page.locator('[data-comment-form] [name=body]').fill('Secret review @'+fixture().users[8].username);
    await page.locator('[data-comment-form] [type=submit]').click();await expect(page.locator('.collaboration-thread')).toContainText('Secret review');
    const privateThreads=await api(request,'get','pages/'+document.id+'/discussions?revision_number=2');const privateThread=privateThreads[0];
    await page.locator('[data-panel-close]').click();await page.locator('[data-task-create]').click();
    const dialog=page.locator('[data-task-dialog]');await dialog.locator('[name=title]').fill('Secret task from review');
    await dialog.locator('[data-task-details] summary').click();
    await expect(dialog.locator('[name=assignee_id] option[value="'+fixture().users[8].id+'"]')).not.toBeAttached();
    await dialog.locator('[data-task-save]').click();await expect(dialog).not.toBeVisible();
    const privateTask=(await api(request,'get','pages/'+document.id+'/tasks'))[0];expect(privateTask.visibility).toBe('working');expect(Number(privateTask.revision_number)).toBe(2);
    const context=await browser.newContext(),reader=await context.newPage();
    try{
        await login(reader,8);await reader.goto(path);await expect(reader.locator('[data-document-content]')).toContainText('Public procedure');
        await reader.locator('[data-discussions-open]').click();await expect(reader.locator('[data-discussions-feedback]')).toHaveText('No discussions.');
        await expect(reader.locator('[data-tasks-feedback]')).toContainText('No tasks.');
        expect(await api(request,'get','pages/'+document.id+'/discussions?revision_number=1',undefined,8)).toEqual([]);
        expect(await api(request,'get','pages/'+document.id+'/tasks',undefined,8)).toEqual([]);
        for(const resource of ['comments/'+privateThread.id,'tasks/'+privateTask.id,'pages/'+document.id+'/discussions?revision_number=2']){
            const result=await request.get('/api/v1/'+resource,{headers:{Authorization:'Bearer '+fixture().workflowTokens[8]}});
            expect(result.status()).toBe(404);expect(await result.text()).not.toContain('Secret');
        }
        await reader.goto(path+'/review#discussion-'+privateThread.id);await expect(reader.locator('body')).not.toContainText('Secret procedure');
        await reader.goto('/notifications');await expect(reader.locator('body')).not.toContainText('Secret');
        await page.goto(path);await select(page,'Public procedure');await page.locator('[data-comment-form] [name=body]').fill('Public discussion @'+fixture().users[8].username);
        await page.locator('[data-comment-form] [type=submit]').click();await expect(page.locator('.collaboration-thread')).toContainText('Public discussion');
        const publicThread=(await api(request,'get','pages/'+document.id+'/discussions?revision_number=1')).find(thread=>thread.visibility==='published');
        await page.locator('.collaboration-thread').getByRole('button',{name:'Create task',exact:true}).click();
        await dialog.locator('[name=title]').fill('Reader assigned task');await dialog.locator('[data-task-details] summary').click();
        await expect(dialog.locator('[name=assignee_id] option[value="'+fixture().users[8].id+'"]')).toBeAttached();
        await dialog.locator('[name=assignee_id]').selectOption(String(fixture().users[8].id));await dialog.locator('[data-task-save]').click();await expect(dialog).not.toBeVisible();
        const publicTask=(await api(request,'get','pages/'+document.id+'/tasks')).find(task=>task.visibility==='published');
        await reader.goto('/?type=task');await expect(reader.locator('[data-work-center]')).toContainText('Reader assigned task');
        await reader.locator('[data-work-center]').getByRole('link',{name:'Open task',exact:true}).click();
        await expect(reader.locator('[data-task-dialog] [name=title]')).toBeDisabled();await reader.locator('[data-task-dialog] [name=status]').selectOption('done');
        await reader.locator('[data-task-save]').click();await expect(reader.locator('[data-tasks-list]')).toContainText('Done');
        await reader.goto('/notifications');await expect(reader.locator('body')).toContainText('Visible publication');
        await page.goto(path+'/permissions');await page.locator('[name=principal]').selectOption('user:'+fixture().users[8].id);
        await page.locator('[name=permission]').selectOption('page.view');await page.locator('[name=effect]').selectOption('deny');await page.getByRole('button',{name:'Save ACL rule',exact:true}).click();
        await reader.goto('/?type=task&status=all');await expect(reader.locator('[data-work-center]')).not.toContainText('Reader assigned task');
        await reader.goto('/notifications');await expect(reader.locator('body')).not.toContainText('Visible publication');
        expect((await api(request,'get','work?type=task&status=all',undefined,8))).toEqual([]);
        for(const resource of ['comments/'+publicThread.id,'tasks/'+publicTask.id]){
            expect((await request.get('/api/v1/'+resource,{headers:{Authorization:'Bearer '+fixture().workflowTokens[8]}})).status()).toBe(404);
        }
    }finally{await context.close();}
});

test('changed, duplicated and removed quotes preserve original anchors and historical source',async({page,request})=>{
    await login(page);const original='<p>First identical quote end.</p><p>Second identical quote tail.</p>';
    const document=await api(request,'post','pages',{space_id:fixture().spaceId,title:'Anchor drift '+Date.now(),content_format:'visual',status:'published',content_html:original});
    const path='/spaces/'+fixture().spaceKey+'/pages/'+document.slug;await page.goto(path);await select(page,'identical quote','[data-document-content]',1);
    await page.locator('[data-comment-form] [name=body]').fill('Anchor second occurrence');await page.locator('[data-comment-form] [type=submit]').click();
    await expect(page.locator('.collaboration-thread')).toContainText('Anchor second occurrence');
    const root=(await api(request,'get','pages/'+document.id+'/discussions?revision_number=1'))[0],start=root.location.start;
    await api(request,'patch','pages/'+document.id,{base_version:1,content_format:'visual',content_html:'<p>Inserted text before.</p>'+original,status:'published'});
    const moved=(await api(request,'get','pages/'+document.id+'/discussions?revision_number=2'))[0];expect(moved.location.start).toBeGreaterThan(start);
    await page.reload();await page.locator('[data-discussions-open]').click();await page.locator('.collaboration-quote').click();
    await expect(page.locator('[data-discussions-feedback]')).not.toContainText('Fragment changed or removed');
    await page.locator('[data-panel-close]').click();const point=await page.locator('[data-document-content]').evaluate(container=>{
        const walker=document.createTreeWalker(container,NodeFilter.SHOW_TEXT);let node,count=0;
        while(node=walker.nextNode()){const i=node.data.indexOf('identical quote');if(i<0||++count!==2)continue;
            const range=document.createRange();range.setStart(node,i);range.setEnd(node,i+15);const rect=range.getBoundingClientRect();return{x:rect.x+5,y:rect.y+rect.height/2};}
    });await page.mouse.click(point.x,point.y);await expect(page.locator('[data-discussions-panel]')).toBeVisible();
    await api(request,'patch','pages/'+document.id,{base_version:2,content_format:'visual',content_html:original+original,status:'published'});
    expect((await api(request,'get','pages/'+document.id+'/discussions?revision_number=3'))[0].location).toBeNull();
    await api(request,'patch','pages/'+document.id,{base_version:3,content_format:'visual',content_html:'<p>All selected text removed.</p>',status:'published'});
    await page.reload();await page.locator('[data-discussions-open]').click();await expect(page.locator('.collaboration-thread')).toContainText('Fragment changed or removed');
    await expect(page.locator('.collaboration-quote')).toHaveText('identical quote');await expect(page.locator('.collaboration-thread').getByRole('button',{name:'Resolve',exact:true})).toBeVisible();
    await page.locator('.collaboration-thread').getByRole('link',{name:'Source revision 1',exact:true}).click();
    await expect(page).toHaveURL(new RegExp('/revisions/1#discussion-'+root.id));await expect(page.locator('[data-discussions-panel]')).toBeVisible();
    await expect(page.locator('[data-revision-content]')).toContainText('Second identical quote tail.');
});
const fixture=()=>JSON.parse(fs.readFileSync('storage/temp/editor-browser.json','utf8'));
const api=async(request,method,path,data,index=null)=>{
    const response=await request[method]('/api/v1/'+path,{headers:{Authorization:'Bearer '+(index===null?fixture().token:fixture().workflowTokens[index])},data});
    expect(response.ok(),await response.text()).toBeTruthy();return (await response.json()).data;
};
const login=async(page,index=0)=>{
    await page.context().addCookies([{name:'openwiki_locale',value:'en',url:'http://127.0.0.1:8765'}]);
    await page.goto('/login');await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);await page.getByRole('button',{name:'Sign in',exact:true}).click();
    await expect(page).toHaveURL('/');
};
const select=async(page,quote,selector='[data-document-content]',occurrence=0)=>{
    await page.locator(selector).evaluate((container,{text,occurrence})=>{
        const walker=document.createTreeWalker(container,NodeFilter.SHOW_TEXT);let node,found=0;
        while((node=walker.nextNode())){let offset=0,start;
            while((start=node.data.indexOf(text,offset))>=0){offset=start+text.length;if(found++!==occurrence)continue;
            const range=document.createRange();range.setStart(node,start);range.setEnd(node,start+text.length);
            const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);return;
            }
        }throw new Error('Quote not found');
    },{text:quote,occurrence});
    await expect(page.locator('[data-comment-selection]')).toBeEnabled();await page.locator('[data-comment-selection]').click();
};
test('real fragment discussion, reply, resolve, reopen and assigned task survive refresh',async({page,request,browser})=>{
    const errors=[];page.on('pageerror',error=>errors.push(error.message));await login(page);
    const document=await api(request,'post','pages',{space_id:fixture().spaceId,title:'Collaboration '+Date.now(),content_format:'visual',content_html:'<p>Before selected fragment after.</p>',status:'published'});
    const path='/spaces/'+fixture().spaceKey+'/pages/'+document.slug;await page.goto(path);
    await select(page,'selected fragment');await page.locator('[data-comment-form] [name=body]').fill('Check the procedure @'+fixture().users[1].username);
    await page.locator('[data-comment-form] [type=submit]').click();const thread=page.locator('.collaboration-thread').first();
    await expect(thread).toContainText('Check the procedure');await thread.getByRole('button',{name:'Reply',exact:true}).click();
    await page.locator('[data-comment-form] [name=body]').fill('Verified in a real reply');await page.locator('[data-comment-form] [type=submit]').click();await expect(thread).toContainText('Verified in a real reply');
    await thread.getByRole('button',{name:'Resolve',exact:true}).click();await expect(thread.getByRole('button',{name:'Reopen',exact:true})).toBeVisible();
    await page.reload();await page.locator('[data-discussions-open]').click();await thread.getByRole('button',{name:'Reopen',exact:true}).click();await expect(thread.getByRole('button',{name:'Resolve',exact:true})).toBeVisible();
    await thread.getByRole('button',{name:'Create task',exact:true}).click();const dialog=page.locator('[data-task-dialog]');
    await dialog.locator('[name=title]').fill('Validate recovery');await dialog.locator('[data-task-details] summary').click();await dialog.locator('[name=description]').fill('Created from the selected discussion');
    await expect(dialog.locator('[name=assignee_id] option[value="'+fixture().users[1].id+'"]').first()).toBeAttached();
    await dialog.locator('[name=assignee_id]').selectOption(String(fixture().users[1].id));await dialog.locator('[name=due_date]').fill('2026-12-10');await dialog.locator('[data-task-save]').click();
    await expect(dialog).not.toBeVisible();await expect(page.locator('[data-tasks-list]')).toContainText('Validate recovery');
    await page.locator('[data-panel-close]').click();await page.locator('[data-tasks-list]').getByRole('button',{name:'Validate recovery'}).click();
    await dialog.locator('[name=status]').selectOption('in_progress');await dialog.locator('[data-task-save]').click();await expect(page.locator('[data-tasks-list]')).toContainText('In progress');
    await page.reload();await page.locator('[data-tasks-list]').getByRole('button',{name:'Validate recovery'}).click();
    await dialog.locator('[data-task-history-section] summary').click();await expect(dialog.locator('[data-task-history]')).toContainText('Version 2');
    const tasks=await api(request,'get','pages/'+document.id+'/tasks');expect(tasks).toHaveLength(1);expect(Number(tasks[0].thread_id)).toBeGreaterThan(0);
    const assigneeContext=await browser.newContext();const assigneePage=await assigneeContext.newPage();
    try{await login(assigneePage,1);await expect(assigneePage.locator('[data-work-center]')).toContainText('Validate recovery');
        await assigneePage.locator('[data-work-center] [name=type]').selectOption('task');await assigneePage.getByRole('button',{name:'Apply filters'}).click();
        await expect(assigneePage).toHaveURL(/type=task/);await expect(assigneePage.locator('[data-work-center]')).toContainText('Validate recovery');
        await assigneePage.screenshot({path:'storage/temp/collaboration-work-center.png'});
    }finally{await assigneeContext.close();}
    expect(errors).toEqual([]);
});
for(const width of [390,768,1440])test('discussion and task panels fit '+width+' pixels',async({page,request})=>{
    await page.setViewportSize({width,height:900});await login(page);
    const document=await api(request,'post','pages',{space_id:fixture().spaceId,title:'Panel '+width+' '+Date.now(),content_format:'visual',content_html:'<h2>Documentation collaboration</h2><p>Select this fragment for a discussion.</p>',status:'published'});
    await page.goto('/spaces/'+fixture().spaceKey+'/pages/'+document.slug);await select(page,'this fragment');
    const panel=page.locator('[data-discussions-panel]');await expect(panel).toBeVisible();await expect(page.locator('[data-discussions-feedback]')).toHaveText('No discussions.');await page.screenshot({path:'storage/temp/collaboration-discussion-'+width+'.png'});
    const bounds=await panel.boundingBox();expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(width+1);
    await panel.locator('[name=body]').fill('Keep this text when closing and reopening');await panel.press('Escape');await expect(panel).not.toBeVisible();await expect(page.locator('[data-comment-selection]')).toBeFocused();
    await page.locator('[data-discussions-open]').click();await expect(panel.locator('[name=body]')).toHaveValue('Keep this text when closing and reopening');await panel.locator('[data-panel-close]').click();
    await page.locator('[data-task-create]').click();const dialog=page.locator('[data-task-dialog]');await expect(dialog).toBeVisible();await dialog.locator('[name=title]').fill('Review recovery steps');
    await expect(dialog.locator('[data-task-save]')).toBeEnabled();await page.screenshot({path:'storage/temp/collaboration-task-'+width+'.png'});const taskBounds=await dialog.boundingBox();expect(taskBounds.x+taskBounds.width).toBeLessThanOrEqual(width+1);await dialog.press('Escape');await expect(page.locator('[data-task-create]')).toBeFocused();
});
test('real concurrent task requests conflict and the form retains unsaved input',async({page,request})=>{
    await login(page);const document=await api(request,'post','pages',{space_id:fixture().spaceId,title:'Conflict '+Date.now(),content_format:'visual',content_html:'<p>Conflict test source.</p>',status:'published'});
    const data={title:'Concurrent task',assignee_id:fixture().users[0].id,operation_id:require('node:crypto').randomUUID()};
    const task=await api(request,'post','pages/'+document.id+'/tasks',data);const retry=await api(request,'post','pages/'+document.id+'/tasks',data);expect(task.id).toBe(retry.id);
    const changes=await Promise.all(['blocked','in_progress'].map(status=>request.patch('/api/v1/tasks/'+task.id,{headers:{Authorization:'Bearer '+fixture().token},data:{base_version:1,status}})));
    expect(changes.map(response=>response.status()).sort()).toEqual([200,409]);
    await page.goto('/spaces/'+fixture().spaceKey+'/pages/'+document.slug);await page.locator('[data-tasks-list]').getByRole('button',{name:'Concurrent task',exact:true}).click();
    const dialog=page.locator('[data-task-dialog]');await dialog.locator('[name=description]').fill('Retain this unsaved description');
    await api(request,'patch','tasks/'+task.id,{base_version:2,due_date:'2026-12-11'});await dialog.locator('[data-task-save]').click();
    await expect(dialog.locator('[data-task-feedback]')).toContainText('Zadanie zostało zmienione');await expect(dialog.locator('[name=description]')).toHaveValue('Retain this unsaved description');
    await dialog.locator('[data-task-reload]').click();await dialog.locator('[data-task-save]').click();await expect(dialog).not.toBeVisible();
    expect((await api(request,'get','tasks/'+task.id)).description).toBe('Retain this unsaved description');
});
