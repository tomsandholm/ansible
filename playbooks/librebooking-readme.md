Ansible Playbooks to install librebooking

You must have the desired target system already configured under Ansible.
Run the following playbooks as the ansible user on the ansible server:

1.  ansible-playbook librebooking-setup.yml
2.  ansible-playbook --limit '<ANSIBLE-CLIENT>' librebooking-mysql.yml
3.  ansible-playbook --limit '<ANSIBLE-CLIENT>' librebooking-apache2.yml
4.  ansible-playbook --limit '<ANSIBLE-CLIENT>' librebooking-php.yml

Login to the target host you just installed to:

http://<ANSIBLE-CLIENT>/

You should see a phpinfo screen with all the details of the
apache server and the php version and modules installed

Enter the following URL to start the install process:

http://<ANSIBLE-CLIENT>/librebooking/Web/install

When prompted for the "Install password", enter the default "password" or use what you changed.

when prompted for the database connection, use
user: root
password: root

Select ALL the items for installation,
create database
create user
import sample data

When completed, you may login using the sample data account, admin/password.


