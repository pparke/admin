create table AdminComponentAdminGroupBinding (
    component integer not null constraint AdminComponentAdminGroupBinding_component references AdminComponent(id),
    groupnum integer not null constraint AdminComponentAdminGroupBinding_groupnum references AdminGroup(id),
	primary key(component, groupnum)
);

-- default AdminComponentAdminGroupBinding bindings
insert into AdminComponentAdminGroupBinding (component, groupnum)
	select AdminComponent.id, AdminGroup.id from AdminComponent, AdminGroup;
