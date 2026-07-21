.PHONY: test-watcher

test-watcher:
	@head -2 ../playbooks/users.csv > /tmp/users-test.csv
	@printf 'Username,Public Key\nstu01,ssh-rsa TESTKEY user@host\n' > /tmp/stu01.csv
	@python3 update-users-csv.py /tmp/stu01.csv /tmp/users-test.csv
	@grep stu01 /tmp/users-test.csv
